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
 respond(200,['assignment'=>['id'=>(int)$a['id'],'lesson_id'=>(int)$a['lesson_id'],'type'=>$a['type'],'title'=>$a['title'],'question'=>$a['question']]]);
}
if($action==='assignment_create'){
 if($method!=='POST')respond(405,['error'=>'Метод не поддерживается']);
 if(!$isAdmin)respond(403,['error'=>'Недостаточно прав']);
 $b=json_decode(file_get_contents('php://input'),true);if(!is_array($b))respond(400,['error'=>'Некорректные данные']);
 $lid=numericId($b['lesson_id']??'');$q=$db->prepare('SELECT id FROM lessons WHERE id=?');$q->execute([$lid]);if(!$q->fetchColumn())respond(404,['error'=>'Урок не найден']);
 $type=(string)($b['type']??'');if($type!=='simple_question')respond(422,['error'=>'Неизвестный тип задания']);
 $title=trim((string)($b['title']??''));$question=trim((string)($b['question']??''));
 if($title===''||mb_strlen($title)>120||$question===''||mb_strlen($question)>10000)respond(422,['error'=>'Укажите название и вопрос']);
 $db->beginTransaction();
 try{
  $q=$db->prepare('INSERT INTO assignments(lesson_id,type,title,question) VALUES(?,?,?,?)');$q->execute([$lid,$type,$title,$question]);$id=(int)$db->lastInsertId();
  $q=$db->prepare('UPDATE lessons SET content_md=content_md||? WHERE id=?');$q->execute(["\n\n[assignment](/assignments/$id)\n",$lid]);
  $db->commit();
 }catch(Throwable $e){$db->rollBack();throw $e;}
 respond(201,['id'=>$id,'ok'=>true]);
}
if($action==='answer_submit'){
 if($method!=='POST')respond(405,['error'=>'Метод не поддерживается']);
 $b=json_decode(file_get_contents('php://input'),true);if(!is_array($b))respond(400,['error'=>'Некорректные данные']);
 $id=numericId($b['assignment_id']??'');$a=assignmentDetails($db,$id);
 if(!assignmentReadable($db,$a,$adminId,$isAdmin))respond(403,['error'=>'Нет доступа к заданию']);
 $answer=trim((string)($b['answer_text']??''));if($answer===''||mb_strlen($answer)>20000)respond(422,['error'=>'Ответ должен содержать от 1 до 20000 символов']);
 $q=$db->prepare('INSERT INTO assignment_answers(assignment_id,user_id,answer_text) VALUES(?,?,?)');$q->execute([$id,$adminId,$answer]);
 respond(201,['ok'=>true,'id'=>(int)$db->lastInsertId()]);
}
if($action==='answers'){
 if($method!=='GET')respond(405,['error'=>'Метод не поддерживается']);
 $scope=(string)($_GET['scope']??'own');if(!in_array($scope,['own','all'],true))respond(422,['error'=>'Неверный режим просмотра']);
 $school=numericId($_GET['school_id']??'');
 if($scope==='all'){$authorized=$isAdmin||hasPermission($db,$adminId,$school,'answers.all.read');}
 else{$authorized=$isAdmin||hasPermission($db,$adminId,$school,'answers.own.read')||hasPermission($db,$adminId,$school,'answers.all.read');}
 if(!$authorized)respond(403,['error'=>'Нет права на просмотр ответов']);
 $aid=isset($_GET['assignment_id'])?numericId($_GET['assignment_id']):0;
 $sql="SELECT aa.id,aa.answer_text,aa.submitted_at,a.id AS assignment_id,a.title,a.type,l.name AS lesson_name,s.name AS subject_name,u.username,u.first_name,u.last_name FROM assignment_answers aa JOIN assignments a ON a.id=aa.assignment_id JOIN lessons l ON l.id=a.lesson_id JOIN subjects s ON s.id=l.subject_id JOIN users u ON u.id=aa.user_id WHERE s.school_id=?";
 $args=[$school];
 if($scope==='own'){$sql.=' AND aa.user_id=?';$args[]=$adminId;}
 if($aid){$sql.=' AND a.id=?';$args[]=$aid;}
 $sql.=' ORDER BY aa.id DESC LIMIT 500';
 $q=$db->prepare($sql);$q->execute($args);respond(200,['answers'=>$q->fetchAll(PDO::FETCH_ASSOC)]);
}
