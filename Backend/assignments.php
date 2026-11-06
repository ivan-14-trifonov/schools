<?php
// Module uses the authenticated session, PDO and permission helpers from api.php.
function assignmentDetails(PDO $db,int $id): array {
 $q=$db->prepare('SELECT a.*,s.school_id,l.name AS lesson_name,s.name AS subject_name FROM assignments a JOIN lessons l ON l.id=a.lesson_id JOIN subjects s ON s.id=l.subject_id WHERE a.id=?');
 $q->execute([$id]);$a=$q->fetch(PDO::FETCH_ASSOC);if(!$a)respond(404,['error'=>'Задание не найдено']);return $a;
}
function assignmentReadable(PDO $db,array $a,int $userId,bool $admin): bool {
 if($admin)return true;
 if(!hasPermission($db,$userId,(int)$a['school_id'],'lessons.read'))return false;
 $q=$db->prepare('SELECT stage_id FROM lesson_stages WHERE lesson_id=?');$q->execute([$a['lesson_id']]);
 foreach($q->fetchAll(PDO::FETCH_COLUMN) as $stageId)if(canSeeStage($db,$userId,(int)$a['school_id'],(int)$stageId))return true;
 return false;
}
function validateCompositeSchema(mixed $schema): string {
 if(!is_array($schema)||!array_is_list($schema)||count($schema)<1||count($schema)>40)respond(422,['error'=>'Добавьте от 1 до 40 полей']);
 $out=[];$ids=[];
 foreach($schema as $field){
  if(!is_array($field))respond(422,['error'=>'Некорректное поле']);
  $id=(string)($field['id']??'');$type=(string)($field['type']??'');$label=trim((string)($field['label']??''));
  if(!preg_match('/^f[0-9]{1,8}$/',$id)||isset($ids[$id])||!in_array($type,['text','select','repeating_group'],true)||$label===''||mb_strlen($label)>200)respond(422,['error'=>'Некорректный тип или название поля']);$ids[$id]=true;
  $entry=['id'=>$id,'type'=>$type,'label'=>$label];
  if($type==='select'){$opts=$field['options']??null;if(!is_array($opts)||!array_is_list($opts)||count($opts)<1||count($opts)>50)respond(422,['error'=>'Список должен содержать от 1 до 50 вариантов']);$options=[];foreach($opts as $opt){if(!is_string($opt)||trim($opt)===''||mb_strlen($opt)>120)respond(422,['error'=>'Некорректный вариант']);$options[]=trim($opt);}if(count(array_unique($options))!==count($options))respond(422,['error'=>'Варианты не должны повторяться']);$entry['options']=$options;$entry['allow_other']=($field['allow_other']??false)===true;}
  if($type==='repeating_group'){$children=$field['fields']??null;if(!is_array($children)||!array_is_list($children)||count($children)<1||count($children)>15)respond(422,['error'=>'Группа должна содержать от 1 до 15 полей']);$encoded=validateCompositeSchema($children);$entry['fields']=json_decode($encoded,true);}
  $out[]=$entry;
 }
 return json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
}
function validateCompositeAnswer(array $schema,mixed $values): array {
 if(!is_array($values)||!array_is_list($values)||count($values)!==count($schema))respond(422,['error'=>'Заполните все поля']);
 $out=[];foreach($schema as $i=>$field){
  $v=$values[$i];if(!is_array($v)||($v['id']??null)!==$field['id'])respond(422,['error'=>'Неверное поле']);
  if($field['type']==='text'){$text=trim((string)($v['text']??''));if($text===''||mb_strlen($text)>10000)respond(422,['error'=>'Заполните текстовое поле']);$out[]=['id'=>$field['id'],'text'=>$text];}
  elseif($field['type']==='select'){$choice=$v['choice']??null;if(!is_string($choice)||($choice!=='__other__'&&!in_array($choice,$field['options'],true))||($choice==='__other__'&&!$field['allow_other']))respond(422,['error'=>'Выберите вариант']);$extra=$choice==='__other__'?trim((string)($v['other']??'')):'';if($choice==='__other__'&&($extra===''||mb_strlen($extra)>1000))respond(422,['error'=>'Укажите другой вариант']);$out[]=['id'=>$field['id'],'choice'=>$choice,'other'=>$extra];}
  else{$rows=$v['rows']??null;if(!is_array($rows)||!array_is_list($rows)||count($rows)<1||count($rows)>100)respond(422,['error'=>'Количество повторений от 1 до 100']);$out[]=['id'=>$field['id'],'rows'=>array_map(fn($row)=>validateCompositeAnswer($field['fields'],$row),$rows)];}
 }
 return $out;
}
function compositeSchema(PDO $db,int $id): ?array {$q=$db->prepare('SELECT schema_json FROM composite_questions WHERE assignment_id=?');$q->execute([$id]);$raw=$q->fetchColumn();return $raw===false?null:json_decode($raw,true);}
if($action==='answer_rights'){
 if($method!=='GET')respond(405,['error'=>'Метод не поддерживается']);
 $school=numericId($_GET['school_id']??'');
 $own=$isAdmin||hasPermission($db,$adminId,$school,'answers.own.read');
 $all=$isAdmin||hasPermission($db,$adminId,$school,'answers.all.read');
 respond(200,['rights'=>['own'=>$own,'all'=>$all]]);
}
if($action==='assignment'){
 $id=numericId($_GET['id']??'');$a=assignmentDetails($db,$id);
 if(!assignmentReadable($db,$a,$adminId,$isAdmin))respond(403,['error'=>'Задание недоступно']);
 if($method!=='GET')respond(405,['error'=>'Метод не поддерживается']);
 $q=$db->prepare('SELECT id,version FROM assignment_versions WHERE assignment_id=? ORDER BY version DESC LIMIT 1');$q->execute([$id]);$v=$q->fetch(PDO::FETCH_ASSOC);
 $schema=compositeSchema($db,$id);respond(200,['assignment'=>['id'=>(int)$a['id'],'lesson_id'=>(int)$a['lesson_id'],'type'=>$schema===null?'simple_question':'composite_question','title'=>$a['title'],'question'=>$a['question'],'fields'=>$schema,'version_id'=>(int)($v['id']??0),'version'=>(int)($v['version']??1)]]);
}
if($action==='assignment_create'){
 if($method!=='POST')respond(405,['error'=>'Метод не поддерживается']);
 if(!$isAdmin)respond(403,['error'=>'Недостаточно прав']);
 $b=json_decode(file_get_contents('php://input'),true);if(!is_array($b))respond(400,['error'=>'Некорректные данные']);
 $lid=numericId($b['lesson_id']??'');$q=$db->prepare('SELECT id FROM lessons WHERE id=?');$q->execute([$lid]);if(!$q->fetchColumn())respond(404,['error'=>'Урок не найден']);
 $type=(string)($b['type']??'');if(!in_array($type,['simple_question','composite_question'],true))respond(422,['error'=>'Неизвестный тип задания']);$schema=$type==='composite_question'?validateCompositeSchema($b['fields']??null):null;
 $title=trim((string)($b['title']??''));$question=trim((string)($b['question']??''));
 if($title===''||mb_strlen($title)>120||$question===''||mb_strlen($question)>10000)respond(422,['error'=>'Укажите название и вопрос']);
 $db->beginTransaction();
 try{
  $q=$db->prepare('INSERT INTO assignments(lesson_id,type,title,question) VALUES(?,?,?,?)');$q->execute([$lid,'simple_question',$title,$question]);$id=(int)$db->lastInsertId();if($schema!==null){$q=$db->prepare('INSERT INTO composite_questions(assignment_id,schema_json) VALUES(?,?)');$q->execute([$id,$schema]);}
  $q=$db->prepare('INSERT INTO assignment_versions(assignment_id,version,title,question,type,schema_json) VALUES(?,1,?,?,?,?)');$q->execute([$id,$title,$question,$type,$schema]);
  $q=$db->prepare('SELECT content_json FROM lessons WHERE id=?');$q->execute([$lid]);$blocks=json_decode((string)$q->fetchColumn(),true)?:[];$blocks[]=['type'=>'markdown','text'=>"\n\n[assignment](/assignments/$id)\n"];$q=$db->prepare('UPDATE lessons SET content_json=? WHERE id=?');$q->execute([validatedLessonBlocks($blocks),$lid]);
  $db->commit();
 }catch(Throwable $e){$db->rollBack();throw $e;}
 respond(201,['id'=>$id,'ok'=>true]);
}
if($action==='assignment_update'){
 if($method!=='PUT')respond(405,['error'=>'Метод не поддерживается']);
 if(!$isAdmin)respond(403,['error'=>'Недостаточно прав']);
 $b=json_decode(file_get_contents('php://input'),true);if(!is_array($b))respond(400,['error'=>'Некорректные данные']);
 $id=numericId($b['id']??'');$a=assignmentDetails($db,$id);
 $type=(string)($b['type']??'');if(!in_array($type,['simple_question','composite_question'],true))respond(422,['error'=>'Неизвестный тип задания']);
 $schema=$type==='composite_question'?validateCompositeSchema($b['fields']??null):null;
 $title=trim((string)($b['title']??''));$question=trim((string)($b['question']??''));
 if($title===''||mb_strlen($title)>120||$question===''||mb_strlen($question)>10000)respond(422,['error'=>'Укажите название и вопрос']);
 $db->beginTransaction();
 try {
  $q=$db->prepare('SELECT id,version FROM assignment_versions WHERE assignment_id=? ORDER BY version DESC LIMIT 1');$q->execute([$id]);$latest=$q->fetch(PDO::FETCH_ASSOC);
  if((int)($b['version_id']??0)!==(int)($latest['id']??0)){$db->rollBack();respond(409,['error'=>'Задание уже изменено в другой вкладке. Откройте редактор заново.']);}
  $q=$db->prepare('UPDATE assignments SET title=?,question=? WHERE id=?');$q->execute([$title,$question,$id]);
  if($schema===null){$q=$db->prepare('DELETE FROM composite_questions WHERE assignment_id=?');$q->execute([$id]);}
  else {$q=$db->prepare('INSERT INTO composite_questions(assignment_id,schema_json) VALUES(?,?) ON CONFLICT(assignment_id) DO UPDATE SET schema_json=excluded.schema_json');$q->execute([$id,$schema]);}
  $q=$db->prepare('INSERT INTO assignment_versions(assignment_id,version,title,question,type,schema_json) VALUES(?,?,?,?,?,?)');$q->execute([$id,(int)($latest['version']??0)+1,$title,$question,$type,$schema]);
  $db->commit();
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
 respond(200,['ok'=>true]);
}
if($action==='answer_submit'){
 if($method!=='POST')respond(405,['error'=>'Метод не поддерживается']);
 $b=json_decode(file_get_contents('php://input'),true);if(!is_array($b))respond(400,['error'=>'Некорректные данные']);
 $id=numericId($b['assignment_id']??'');$a=assignmentDetails($db,$id);
 if(!assignmentReadable($db,$a,$adminId,$isAdmin))respond(403,['error'=>'Нет доступа к заданию']);
 $q=$db->prepare('SELECT id,type,schema_json FROM assignment_versions WHERE assignment_id=? ORDER BY version DESC LIMIT 1');$q->execute([$id]);$version=$q->fetch(PDO::FETCH_ASSOC);
 if(!$version)respond(409,['error'=>'Версия задания не найдена']);
 if((int)($b['version_id']??0)!==(int)$version['id'])respond(409,['error'=>'Задание изменилось. Обновите страницу и отправьте ответ заново.']);
 $schema=$version['type']==='composite_question'?json_decode($version['schema_json'],true):null;
 if($schema!==null){$answer=json_encode(validateCompositeAnswer($schema,$b['values']??null),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}else{$answer=trim((string)($b['answer_text']??''));if($answer===''||mb_strlen($answer)>20000)respond(422,['error'=>'Ответ должен содержать от 1 до 20000 символов']);}
 $q=$db->prepare('INSERT INTO assignment_answers(assignment_id,user_id,answer_text,version_id) VALUES(?,?,?,?)');$q->execute([$id,$adminId,$answer,$version['id']]);
 respond(201,['ok'=>true,'id'=>(int)$db->lastInsertId()]);
}
if($action==='answer_delete'){
 if($method!=='DELETE')respond(405,['error'=>'Метод не поддерживается']);
 if(!$isAdmin)respond(403,['error'=>'Удалять отправленные ответы может только суперадминистратор']);
 $body=json_decode(file_get_contents('php://input'),true);
 if(!is_array($body))respond(400,['error'=>'Некорректные данные']);
 $id=numericId($body['id']??null);
 $q=$db->prepare('DELETE FROM assignment_answers WHERE id=?');$q->execute([$id]);
 if($q->rowCount()!==1)respond(404,['error'=>'Отправленный ответ не найден']);
 respond(200,['ok'=>true]);
}
if($action==='answers'){
 if($method!=='GET')respond(405,['error'=>'Метод не поддерживается']);
 $scope=(string)($_GET['scope']??'own');if(!in_array($scope,['own','all'],true))respond(422,['error'=>'Неверный режим просмотра']);
 $school=numericId($_GET['school_id']??'');
 if($scope==='all'){$authorized=$isAdmin||hasPermission($db,$adminId,$school,'answers.all.read');}
 else{$authorized=$isAdmin||hasPermission($db,$adminId,$school,'answers.own.read')||hasPermission($db,$adminId,$school,'answers.all.read');}
 if(!$authorized)respond(403,['error'=>'Нет права на просмотр ответов']);
 $aid=isset($_GET['assignment_id'])?numericId($_GET['assignment_id']):0;
 $sql="SELECT aa.id,aa.answer_text,aa.submitted_at,a.id AS assignment_id,COALESCE(av.title,a.title) AS title,COALESCE(av.question,a.question) AS question,COALESCE(av.type,CASE WHEN cq.assignment_id IS NOT NULL THEN 'composite_question' ELSE a.type END) AS type,COALESCE(av.schema_json,cq.schema_json) AS schema_json,av.version,l.name AS lesson_name,s.name AS subject_name,u.username,u.first_name,u.last_name FROM assignment_answers aa JOIN assignments a ON a.id=aa.assignment_id LEFT JOIN composite_questions cq ON cq.assignment_id=a.id LEFT JOIN assignment_versions av ON av.id=aa.version_id JOIN lessons l ON l.id=a.lesson_id JOIN subjects s ON s.id=l.subject_id JOIN users u ON u.id=aa.user_id WHERE s.school_id=?";
 $args=[$school];
 if($scope==='own'){$sql.=' AND aa.user_id=?';$args[]=$adminId;}
 if($aid){$sql.=' AND a.id=?';$args[]=$aid;}
 $sql.=' ORDER BY aa.id DESC LIMIT 500';
 $q=$db->prepare($sql);$q->execute($args);respond(200,['answers'=>$q->fetchAll(PDO::FETCH_ASSOC)]);
}
