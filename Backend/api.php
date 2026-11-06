<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function respond(int $status,array $body): never {http_response_code($status);echo json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function nameOf(array $b): string {$name=trim((string)($b['name']??''));if($name===''||mb_strlen($name)>120)respond(422,['error'=>'Название должно содержать от 1 до 120 символов']);return $name;}
function numericId(mixed $value): int {if(!is_scalar($value)||!ctype_digit((string)$value)||(int)$value<1)respond(422,['error'=>'Некорректный идентификатор']);return (int)$value;}
function exists(PDO $db,string $table,int $id): void {$q=$db->prepare("SELECT 1 FROM $table WHERE id=?");$q->execute([$id]);if(!$q->fetchColumn())respond(404,['error'=>'Запись не найдена']);}
try {
$db=new PDO('sqlite:'.__DIR__.'/../data/schools.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA busy_timeout=5000');
$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE IF NOT EXISTS schools (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL COLLATE NOCASE UNIQUE, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
$cols=$db->query('PRAGMA table_info(schools)')->fetchAll(PDO::FETCH_ASSOC);
if(!in_array('description',array_column($cols,'name'),true))$db->exec("ALTER TABLE schools ADD COLUMN description TEXT NOT NULL DEFAULT ''");
$db->exec('CREATE TABLE IF NOT EXISTS subjects (id INTEGER PRIMARY KEY AUTOINCREMENT, school_id INTEGER NOT NULL REFERENCES schools(id) ON DELETE CASCADE, name TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
$db->exec('CREATE TABLE IF NOT EXISTS lessons (id INTEGER PRIMARY KEY AUTOINCREMENT, subject_id INTEGER NOT NULL REFERENCES subjects(id) ON DELETE CASCADE, name TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
$db->exec('CREATE TABLE IF NOT EXISTS stages (id INTEGER PRIMARY KEY AUTOINCREMENT, school_id INTEGER NOT NULL REFERENCES schools(id) ON DELETE CASCADE, name TEXT NOT NULL, position INTEGER NOT NULL DEFAULT 0)');
$mdCols=$db->query('PRAGMA table_info(lessons)')->fetchAll(PDO::FETCH_ASSOC);
if(!in_array('content_md',array_column($mdCols,'name'),true))$db->exec("ALTER TABLE lessons ADD COLUMN content_md TEXT NOT NULL DEFAULT ''");
$blockCols=$db->query('PRAGMA table_info(lessons)')->fetchAll(PDO::FETCH_ASSOC);
if(!in_array('content_json',array_column($blockCols,'name'),true))$db->exec("ALTER TABLE lessons ADD COLUMN content_json TEXT");
function legacyBlocks(string $source): array {
 $parts=preg_split('~(?m)^[ \\t]*\\[audio\\]\\(([^\\r\\n)]+)\\)[ \\t]*(?:\\r?\\n|$)~u',$source,-1,PREG_SPLIT_DELIM_CAPTURE);
 if($parts===false)return [['type'=>'markdown','text'=>$source]];
 $blocks=[];foreach($parts as $index=>$part) {
  if($index%2===0){if($part!=='')$blocks[]=['type'=>'markdown','text'=>$part];}
  else $blocks[]=['type'=>'audio','src'=>$part];
 }
 return $blocks;
}
function validatedLessonBlocks(mixed $raw): string {
 if(!is_array($raw)||!array_is_list($raw)||count($raw)>500)respond(422,['error'=>'Некорректный список блоков']);
 $out=[];foreach($raw as $b){
  if(!is_array($b)||!isset($b['type'])||!is_string($b['type']))respond(422,['error'=>'Неизвестный блок']);
  if($b['type']==='markdown'){$value=$b['text']??null;if(!is_string($value))respond(422,['error'=>'Некорректный Markdown']);if($value==='')continue;if($out&&$out[count($out)-1]['type']==='markdown')$out[count($out)-1]['text'].=$value;else $out[]=['type'=>'markdown','text'=>$value];}
  elseif($b['type']==='audio'){$src=$b['src']??null;if(!is_string($src)||strlen($src)>2048||!preg_match('~^(?:/media/[a-zA-Z0-9_./-]+|https://[^\\s]+)$~',$src))respond(422,['error'=>'Некорректная ссылка на аудио']);$out[]=['type'=>'audio','src'=>$src];}
  else respond(422,['error'=>'Неподдерживаемый тип блока']);
 }
 $json=json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
 if(strlen($json)>500000)respond(422,['error'=>'Содержимое урока слишком длинное']);
 return $json;
}
$legacyRows=$db->query('SELECT id,content_md FROM lessons WHERE content_json IS NULL')->fetchAll(PDO::FETCH_ASSOC);
if($legacyRows){$db->beginTransaction();try{$update=$db->prepare('UPDATE lessons SET content_json=? WHERE id=? AND content_json IS NULL');foreach($legacyRows as $r)$update->execute([json_encode(legacyBlocks($r['content_md']),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),$r['id']]);$db->commit();}catch(Throwable $e){$db->rollBack();throw $e;}}
$db->exec('CREATE TABLE IF NOT EXISTS lesson_stages (lesson_id INTEGER NOT NULL REFERENCES lessons(id) ON DELETE CASCADE, stage_id INTEGER NOT NULL REFERENCES stages(id) ON DELETE CASCADE, PRIMARY KEY(lesson_id,stage_id))');
$stageCols=$db->query('PRAGMA table_info(stages)')->fetchAll(PDO::FETCH_ASSOC);
if(!in_array('invite_code',array_column($stageCols,'name'),true))$db->exec('ALTER TABLE stages ADD COLUMN invite_code TEXT');
$db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_stages_invite_code ON stages(invite_code)');
function newStageCode(PDO $db): string {do{$code=strtoupper(bin2hex(random_bytes(6)));$q=$db->prepare('SELECT 1 FROM stages WHERE invite_code=?');$q->execute([$code]);}while($q->fetchColumn());return $code;}
$missing=$db->query('SELECT id FROM stages WHERE invite_code IS NULL')->fetchAll(PDO::FETCH_COLUMN);foreach($missing as $id){$q=$db->prepare('UPDATE stages SET invite_code=? WHERE id=?');$q->execute([newStageCode($db),$id]);}
$db->exec('CREATE INDEX IF NOT EXISTS idx_stage_school ON stages(school_id,position,id)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_lesson_stages_stage ON lesson_stages(stage_id)');
$db->exec('CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT NOT NULL COLLATE NOCASE UNIQUE, password_hash TEXT NOT NULL, is_platform_admin INTEGER NOT NULL DEFAULT 0 CHECK(is_platform_admin IN(0,1)), created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
$db->exec("CREATE TABLE IF NOT EXISTS assignments (id INTEGER PRIMARY KEY AUTOINCREMENT, lesson_id INTEGER NOT NULL REFERENCES lessons(id) ON DELETE CASCADE, type TEXT NOT NULL CHECK(type='simple_question'), title TEXT NOT NULL, question TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
$db->exec("CREATE TABLE IF NOT EXISTS assignment_answers (id INTEGER PRIMARY KEY AUTOINCREMENT, assignment_id INTEGER NOT NULL REFERENCES assignments(id) ON DELETE CASCADE, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, answer_text TEXT NOT NULL, submitted_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
$db->exec('CREATE INDEX IF NOT EXISTS idx_assignment_answers_user ON assignment_answers(user_id,submitted_at)');
$db->exec('CREATE TABLE IF NOT EXISTS composite_questions (assignment_id INTEGER PRIMARY KEY REFERENCES assignments(id) ON DELETE CASCADE, schema_json TEXT NOT NULL)');
$db->exec("CREATE TABLE IF NOT EXISTS assignment_versions (id INTEGER PRIMARY KEY AUTOINCREMENT, assignment_id INTEGER NOT NULL REFERENCES assignments(id) ON DELETE CASCADE, version INTEGER NOT NULL, title TEXT NOT NULL, question TEXT NOT NULL, type TEXT NOT NULL CHECK(type IN ('simple_question','composite_question')), schema_json TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(assignment_id,version))");
$answerCols=$db->query('PRAGMA table_info(assignment_answers)')->fetchAll(PDO::FETCH_ASSOC);
if(!in_array('version_id',array_column($answerCols,'name'),true))$db->exec('ALTER TABLE assignment_answers ADD COLUMN version_id INTEGER REFERENCES assignment_versions(id)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_answers_version ON assignment_answers(version_id)');
$unversioned=$db->query('SELECT a.id,a.title,a.question,c.schema_json FROM assignments a LEFT JOIN composite_questions c ON c.assignment_id=a.id WHERE NOT EXISTS(SELECT 1 FROM assignment_versions v WHERE v.assignment_id=a.id)')->fetchAll(PDO::FETCH_ASSOC);
if($unversioned){$insertV=$db->prepare('INSERT INTO assignment_versions(assignment_id,version,title,question,type,schema_json) VALUES(?,1,?,?,?,?)');foreach($unversioned as $a)$insertV->execute([$a['id'],$a['title'],$a['question'],$a['schema_json']===null?'simple_question':'composite_question',$a['schema_json']]);}
$db->exec('UPDATE assignment_answers SET version_id=(SELECT id FROM assignment_versions WHERE assignment_id=assignment_answers.assignment_id ORDER BY version LIMIT 1) WHERE version_id IS NULL');

// Permissions are defined in code; roles and their grants are managed by platform admins.
define('SCHOOL_PERMISSIONS', [
 'lessons.read'=>'Чтение уроков',
 'answers.own.read'=>'Просмотр своих ответов на задания',
 'answers.all.read'=>'Просмотр ответов всех студентов',
 'lessons.manage'=>'Управление уроками',
 'subjects.manage'=>'Управление предметами',
 'stages.manage'=>'Управление этапами обучения',
 'schools.manage'=>'Управление школами',
 'students.manage'=>'Управление учениками',
 'users.manage'=>'Управление пользователями',
 'roles.manage'=>'Управление ролями'
]);
$db->exec('CREATE TABLE IF NOT EXISTS roles (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL COLLATE NOCASE UNIQUE, is_default_student INTEGER NOT NULL DEFAULT 0 CHECK(is_default_student IN (0,1)))');
$db->exec('CREATE TABLE IF NOT EXISTS role_permissions (role_id INTEGER NOT NULL REFERENCES roles(id) ON DELETE CASCADE, permission TEXT NOT NULL, PRIMARY KEY(role_id,permission))');
$db->exec('CREATE TABLE IF NOT EXISTS school_memberships (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, school_id INTEGER NOT NULL REFERENCES schools(id) ON DELETE CASCADE, current_stage_id INTEGER REFERENCES stages(id) ON DELETE SET NULL, UNIQUE(user_id,school_id))');
$memberCols=$db->query('PRAGMA table_info(school_memberships)')->fetchAll(PDO::FETCH_ASSOC);
if(!in_array('stage_access_override',array_column($memberCols,'name'),true))$db->exec("ALTER TABLE school_memberships ADD COLUMN stage_access_override TEXT NOT NULL DEFAULT 'default' CHECK(stage_access_override IN ('default','none','all'))");
$db->exec('CREATE TABLE IF NOT EXISTS membership_roles (membership_id INTEGER NOT NULL REFERENCES school_memberships(id) ON DELETE CASCADE, role_id INTEGER NOT NULL REFERENCES roles(id) ON DELETE CASCADE, PRIMARY KEY(membership_id,role_id))');
$schoolCols=$db->query('PRAGMA table_info(schools)')->fetchAll(PDO::FETCH_ASSOC);
if(!in_array('stage_access_mode',array_column($schoolCols,'name'),true))$db->exec("ALTER TABLE schools ADD COLUMN stage_access_mode TEXT NOT NULL DEFAULT 'current'");
if(!in_array('invite_role_id',array_column($schoolCols,'name'),true))$db->exec('ALTER TABLE schools ADD COLUMN invite_role_id INTEGER REFERENCES roles(id) ON DELETE SET NULL');
if(!$db->query('SELECT 1 FROM roles LIMIT 1')->fetchColumn()){
 $db->prepare('INSERT INTO roles(name,is_default_student) VALUES(?,1)')->execute(['Ученик']);
 $id=(int)$db->lastInsertId();$db->prepare('INSERT INTO role_permissions(role_id,permission) VALUES(?,?)')->execute([$id,'lessons.read']);$db->prepare('INSERT INTO role_permissions(role_id,permission) VALUES(?,?)')->execute([$id,'answers.own.read']);
}
$defaultRole=$db->query('SELECT id FROM roles WHERE is_default_student=1 LIMIT 1')->fetchColumn();
if($defaultRole)$db->prepare('UPDATE schools SET invite_role_id=? WHERE invite_role_id IS NULL')->execute([(int)$defaultRole]);
function hasPermission(PDO $db,int $userId,int $schoolId,string $permission): bool {
 $q=$db->prepare('SELECT 1 FROM users WHERE id=? AND is_platform_admin=1');$q->execute([$userId]);if($q->fetchColumn())return true;
 $q=$db->prepare('SELECT 1 FROM school_memberships m JOIN membership_roles mr ON mr.membership_id=m.id JOIN role_permissions rp ON rp.role_id=mr.role_id WHERE m.user_id=? AND m.school_id=? AND rp.permission=? LIMIT 1');
 $q->execute([$userId,$schoolId,$permission]);return (bool)$q->fetchColumn();
}
function canSeeStage(PDO $db,int $userId,int $schoolId,int $stageId): bool {
 $q=$db->prepare('SELECT 1 FROM users WHERE id=? AND is_platform_admin=1');$q->execute([$userId]);if($q->fetchColumn())return true;
 if(!hasPermission($db,$userId,$schoolId,'lessons.read'))return false;
 $q=$db->prepare('SELECT m.current_stage_id,m.stage_access_override,s.stage_access_mode FROM school_memberships m JOIN schools s ON s.id=m.school_id WHERE m.user_id=? AND m.school_id=?');$q->execute([$userId,$schoolId]);$membership=$q->fetch(PDO::FETCH_ASSOC);if(!$membership||$membership['stage_access_override']==='none')return false;
 if($membership['stage_access_override']==='all')return true;
 if(!$membership['current_stage_id'])return false;
 $q=$db->prepare('SELECT id,position FROM stages WHERE school_id=? AND id IN (?,?)');$q->execute([$schoolId,$stageId,$membership['current_stage_id']]);$positions=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row)$positions[(int)$row['id']]=(int)$row['position'];
 if(!isset($positions[$stageId],$positions[(int)$membership['current_stage_id']]))return false;
 return match($membership['stage_access_mode']){'all'=>true,'previous'=>$positions[$stageId]<=$positions[(int)$membership['current_stage_id']],default=>$stageId===(int)$membership['current_stage_id']};
}
$userCols=$db->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC);
if(!in_array('first_name',array_column($userCols,'name'),true))$db->exec("ALTER TABLE users ADD COLUMN first_name TEXT NOT NULL DEFAULT ''");
if(!in_array('last_name',array_column($userCols,'name'),true))$db->exec("ALTER TABLE users ADD COLUMN last_name TEXT NOT NULL DEFAULT ''");
$db->exec('CREATE INDEX IF NOT EXISTS idx_subject_school ON subjects(school_id)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_lessons_subject ON lessons(subject_id)');
$method=$_SERVER['REQUEST_METHOD']??'GET';$action=$_GET['action']??'schools';
$sessionDir=__DIR__.'/../data/sessions';if(!is_dir($sessionDir))mkdir($sessionDir,0700,true);
session_name('SCHOOLSID');session_save_path($sessionDir);ini_set('session.use_strict_mode','1');ini_set('session.use_only_cookies','1');ini_set('session.cookie_httponly','1');ini_set('session.cookie_samesite','Lax');session_start(['cookie_lifetime'=>0]);
$hasAdmin=(bool)$db->query('SELECT 1 FROM users WHERE is_platform_admin=1 LIMIT 1')->fetchColumn();
$adminId=(int)($_SESSION['user_id']??$_SESSION['admin_id']??0);
$isAdmin=false;$isLoggedIn=false;if($adminId>0){$q=$db->prepare('SELECT is_platform_admin FROM users WHERE id=?');$q->execute([$adminId]);$flag=$q->fetchColumn();$isLoggedIn=$flag!==false;$isAdmin=$isLoggedIn&&(bool)$flag;}
if($action==='auth_status')respond(200,['needs_setup'=>!$hasAdmin,'authenticated'=>$isLoggedIn,'is_admin'=>$isAdmin,'username'=>$isLoggedIn?($_SESSION['username']??''):null]);
if($action==='setup'){
 if($hasAdmin)respond(403,['error'=>'Суперадминистратор уже существует']);
 if($method==='GET'){
  if(!isset($_SESSION['setup_challenge'])||!is_string($_SESSION['setup_challenge'])||!preg_match('/^[a-z]{5}$/D',$_SESSION['setup_challenge'])||time()>(int)($_SESSION['setup_expires']??0)){
   $_SESSION['setup_challenge']=implode('',array_map(static fn()=>chr(random_int(97,122)),range(1,5)));$_SESSION['setup_expires']=time()+1800;
  }
  respond(200,['file'=>$_SESSION['setup_challenge'],'challenge'=>$_SESSION['setup_challenge']]);
 }
 if($method!=='POST')respond(405,['error'=>'Метод не поддерживается']);
 $body=json_decode(file_get_contents('php://input'),true);if(!is_array($body))respond(400,['error'=>'Некорректный JSON']);
 $username=trim((string)($body['username']??''));$password=(string)($body['password']??'');
 if(!preg_match('/^[a-zA-Z0-9_.-]{3,40}$/D',$username))respond(422,['error'=>'Логин: 3–40 латинских букв, цифр, точек, дефисов или подчёркиваний']);
 if(mb_strlen($password)<5||mb_strlen($password)>256)respond(422,['error'=>'Пароль должен содержать от 5 до 256 символов']);
 if(!isset($_SESSION['setup_challenge'])||!is_string($_SESSION['setup_challenge'])||!preg_match('/^[a-z]{5}$/D',$_SESSION['setup_challenge'])||time()>(int)($_SESSION['setup_expires']??0))respond(403,['error'=>'Подтверждение устарело. Обновите страницу.']);
 $proof=__DIR__.'/../'.$_SESSION['setup_challenge'];
 if(!is_file($proof)||is_link($proof)||filesize($proof)!==0)respond(403,['error'=>'Создайте пустой файл с указанным именем в корне проекта']);
 try{
  $db->beginTransaction();
  if($db->query('SELECT 1 FROM users WHERE is_platform_admin=1 LIMIT 1')->fetchColumn()){$db->rollBack();respond(409,['error'=>'Суперадминистратор уже существует']);}
  $q=$db->prepare('INSERT INTO users(username,password_hash,is_platform_admin) VALUES(?,?,1)');
  $q->execute([$username,password_hash($password,PASSWORD_ARGON2ID)]);$id=(int)$db->lastInsertId();$db->commit();
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
 @unlink($proof);unset($_SESSION['setup_challenge'],$_SESSION['setup_expires']);session_regenerate_id(true);$_SESSION['user_id']=$id;$_SESSION['username']=$username;
 respond(201,['ok'=>true]);
}
if(!$hasAdmin)respond(403,['error'=>'Создайте первого суперадминистратора']);
if($action==='login'){
 if($method!=='POST')respond(405,['error'=>'Метод не поддерживается']);
 $body=json_decode(file_get_contents('php://input'),true);if(!is_array($body))respond(400,['error'=>'Некорректный JSON']);
 $q=$db->prepare('SELECT id,username,password_hash FROM users WHERE username=? COLLATE NOCASE');$q->execute([trim((string)($body['username']??''))]);$user=$q->fetch(PDO::FETCH_ASSOC);
 if(!$user||!password_verify((string)($body['password']??''),$user['password_hash']))respond(401,['error'=>'Неверный логин или пароль']);
 session_regenerate_id(true);$_SESSION['admin_id']=(int)$user['id'];$_SESSION['username']=$user['username'];respond(200,['ok'=>true]);
}
if($action==='username_check' && $method==='GET'){
 $username=trim((string)($_GET['username']??''));
 if(!preg_match('/^[a-zA-Z0-9_.-]{3,40}$/D',$username))respond(422,['error'=>'Логин: 3–40 латинских букв, цифр, точек, дефисов или подчёркиваний']);
 $q=$db->prepare('SELECT 1 FROM users WHERE username=? COLLATE NOCASE');$q->execute([$username]);$available=!$q->fetchColumn();$suggestions=[];
 if(!$available){
  $stem=substr($username,0,30);
  for($i=1;$i<=35 && count($suggestions)<5;$i++){
   $candidate=$stem.($i<10?'0':'').$i;
   $q->execute([$candidate]);if(!$q->fetchColumn())$suggestions[]=$candidate;
  }
 }
 respond(200,['available'=>$available,'suggestions'=>$suggestions]);
}
if($action==='invite_info' && $method==='GET'){
 $code=strtoupper(trim((string)($_GET['code']??'')));$q=$db->prepare('SELECT st.id AS stage_id,st.name AS stage_name,s.id AS school_id,s.name AS school_name FROM stages st JOIN schools s ON s.id=st.school_id WHERE st.invite_code=?');$q->execute([$code]);$item=$q->fetch(PDO::FETCH_ASSOC);if(!$item)respond(404,['error'=>'Неверный код приглашения']);respond(200,['invite'=>$item]);
}
if($action==='register' || $action==='join'){
 if($method!=='POST')respond(405,['error'=>'Метод не поддерживается']);$body=json_decode(file_get_contents('php://input'),true);if(!is_array($body))respond(400,['error'=>'Некорректный JSON']);
 $code=strtoupper(trim((string)($body['code']??'')));$q=$db->prepare('SELECT st.id AS stage_id,s.id AS school_id,s.invite_role_id FROM stages st JOIN schools s ON s.id=st.school_id WHERE st.invite_code=?');$q->execute([$code]);$invite=$q->fetch(PDO::FETCH_ASSOC);if(!$invite||!$invite['invite_role_id'])respond(422,['error'=>'Недействительный код приглашения']);
 $roleId=(int)$invite['invite_role_id'];$q=$db->prepare("SELECT 1 FROM role_permissions WHERE role_id=? AND permission IN ('roles.manage','users.manage','students.manage','schools.manage','stages.manage','subjects.manage','lessons.manage') LIMIT 1");$q->execute([$roleId]);if($q->fetchColumn())respond(403,['error'=>'Роль приглашения содержит управляющие права']);
 if($action==='join'&&!$isLoggedIn)respond(401,['error'=>'Сначала войдите в свой аккаунт']);
 if($action==='register'){
  if($isLoggedIn)respond(409,['error'=>'Вы уже вошли. Используйте присоединение по коду']);
  $username=trim((string)($body['username']??''));$password=(string)($body['password']??'');
  if(!preg_match('/^[a-zA-Z0-9_.-]{3,40}$/D',$username))respond(422,['error'=>'Логин: 3–40 латинских букв, цифр, точек, дефисов или подчёркиваний']);
  if(mb_strlen($password)<5||mb_strlen($password)>256)respond(422,['error'=>'Пароль должен содержать от 5 до 256 символов']);
  $firstName=trim((string)($body['first_name']??''));$lastName=trim((string)($body['last_name']??''));
  if($firstName===''||mb_strlen($firstName)>100||$lastName===''||mb_strlen($lastName)>100)respond(422,['error'=>'Укажите имя и фамилию (до 100 символов каждое)']);
 }
 $db->beginTransaction();try{
  if($action==='register'){$q=$db->prepare('INSERT INTO users(username,password_hash,is_platform_admin,first_name,last_name) VALUES(?,?,0,?,?)');$q->execute([$username,password_hash($password,PASSWORD_ARGON2ID),$firstName,$lastName]);$uid=(int)$db->lastInsertId();}
  else{$uid=$adminId;$username=$_SESSION['username'];}
  $q=$db->prepare('SELECT id FROM school_memberships WHERE user_id=? AND school_id=?');$q->execute([$uid,$invite['school_id']]);if($q->fetchColumn()){ $db->rollBack();respond(409,['error'=>'Вы уже состоите в этой школе']); }
  $q=$db->prepare('INSERT INTO school_memberships(user_id,school_id,current_stage_id) VALUES(?,?,?)');$q->execute([$uid,$invite['school_id'],$invite['stage_id']]);$membership=(int)$db->lastInsertId();
  $db->prepare('INSERT INTO membership_roles(membership_id,role_id) VALUES(?,?)')->execute([$membership,$roleId]);$db->commit();
 }catch(PDOException $e){if($db->inTransaction())$db->rollBack();if(str_contains($e->getMessage(),'UNIQUE'))respond(409,['error'=>'Логин занят или участие уже существует']);throw $e;}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
 if($action==='register'){session_regenerate_id(true);$_SESSION['user_id']=$uid;$_SESSION['username']=$username;}
 respond(201,['ok'=>true,'school_id'=>(int)$invite['school_id']]);
}
if($action==='logout'){
 if($method!=='POST')respond(405,['error'=>'Метод не поддерживается']);
 $_SESSION=[];session_regenerate_id(true);respond(200,['ok'=>true]);
}
if($action==='change_password'){
 if($method!=='POST')respond(405,['error'=>'Метод не поддерживается']);
 if(!$isLoggedIn)respond(401,['error'=>'Необходимо войти']);
 $body=json_decode(file_get_contents('php://input'),true);if(!is_array($body))respond(400,['error'=>'Некорректный JSON']);
 $old=(string)($body['old_password']??'');$new=(string)($body['new_password']??'');
 if(mb_strlen($new)<5||mb_strlen($new)>256)respond(422,['error'=>'Новый пароль должен содержать от 5 до 256 символов']);
 $q=$db->prepare('SELECT password_hash FROM users WHERE id=?');$q->execute([$adminId]);$hash=$q->fetchColumn();
 if(!is_string($hash)||!password_verify($old,$hash))respond(403,['error'=>'Неверный текущий пароль']);
 if(hash_equals($old,$new))respond(422,['error'=>'Новый пароль должен отличаться от текущего']);
 $q=$db->prepare('UPDATE users SET password_hash=? WHERE id=?');$q->execute([password_hash($new,PASSWORD_ARGON2ID),$adminId]);
 session_regenerate_id(true);
 respond(200,['ok'=>true]);
}
if(!$isLoggedIn)respond(401,['error'=>'Необходимо войти']);
require_once __DIR__.'/assignments.php';
if($action==='my_schools' && $method==='GET'){
 if($isAdmin){$q=$db->query('SELECT id,name,description,NULL AS current_stage_name FROM schools ORDER BY name COLLATE NOCASE');respond(200,['schools'=>$q->fetchAll(PDO::FETCH_ASSOC)]);}
 $q=$db->prepare("SELECT s.id,s.name,s.description,CASE m.stage_access_override WHEN 'all' THEN 'Все этапы' WHEN 'none' THEN 'Нет этапа' ELSE st.name END AS current_stage_name FROM schools s JOIN school_memberships m ON m.school_id=s.id LEFT JOIN stages st ON st.id=m.current_stage_id AND st.school_id=s.id WHERE m.user_id=? ORDER BY s.name");$q->execute([$adminId]);respond(200,['schools'=>$q->fetchAll(PDO::FETCH_ASSOC)]);
}
if(!$isAdmin && $method==='GET' && in_array($action,['schools','subjects','lessons','stages'],true)){
 if($action==='schools'){$q=$db->prepare("SELECT s.id,s.name,s.description,CASE m.stage_access_override WHEN 'all' THEN 'Все этапы' WHEN 'none' THEN 'Нет этапа' ELSE st.name END AS current_stage_name FROM schools s JOIN school_memberships m ON m.school_id=s.id LEFT JOIN stages st ON st.id=m.current_stage_id AND st.school_id=s.id WHERE m.user_id=? ORDER BY s.name");$q->execute([$adminId]);respond(200,['schools'=>$q->fetchAll(PDO::FETCH_ASSOC)]);}
 $sid=0;$subject=0;
 if($action==='lessons'){$subject=numericId($_GET['subject_id']??'');$q=$db->prepare('SELECT school_id FROM subjects WHERE id=?');$q->execute([$subject]);$sid=(int)$q->fetchColumn();}
 else{$sid=numericId($_GET['school_id']??'');}
 if(!$sid||!hasPermission($db,$adminId,$sid,'lessons.read'))respond(403,['error'=>'Нет доступа к школе']);
 $q=$db->prepare('SELECT m.current_stage_id,m.stage_access_override,s.stage_access_mode FROM school_memberships m JOIN schools s ON s.id=m.school_id WHERE m.user_id=? AND m.school_id=?');$q->execute([$adminId,$sid]);$membership=$q->fetch(PDO::FETCH_ASSOC);if(!$membership||$membership['stage_access_override']==='none')respond(200,[$action=>[]]);
 $q=$db->prepare('SELECT id,name,position FROM stages WHERE school_id=? ORDER BY position,id');$q->execute([$sid]);$allStages=$q->fetchAll(PDO::FETCH_ASSOC);$current=null;foreach($allStages as $st)if((int)$st['id']===(int)$membership['current_stage_id'])$current=(int)$st['position'];
 $allowed=[];if($membership['stage_access_override']==='all')$allowed=array_map(static fn($st)=>(int)$st['id'],$allStages);else if($current!==null)foreach($allStages as $st)if($membership['stage_access_mode']==='all'||($membership['stage_access_mode']==='previous'&&(int)$st['position']<=$current)||($membership['stage_access_mode']==='current'&&(int)$st['id']===(int)$membership['current_stage_id']))$allowed[]=(int)$st['id'];
 if($action==='stages'){respond(200,['stages'=>array_values(array_filter($allStages,fn($st)=>in_array((int)$st['id'],$allowed,true)))]);}
 if(!$allowed)respond(200,[$action=>[]]);$slots=implode(',',array_fill(0,count($allowed),'?'));
 if($action==='lessons'){$q=$db->prepare("SELECT DISTINCT l.id,l.subject_id,l.name,l.content_json FROM lessons l JOIN lesson_stages ls ON ls.lesson_id=l.id WHERE l.subject_id=? AND ls.stage_id IN ($slots) ORDER BY l.id");$q->execute(array_merge([$subject],$allowed));$rows=$q->fetchAll(PDO::FETCH_ASSOC);foreach($rows as &$row){$get=$db->prepare('SELECT stage_id FROM lesson_stages WHERE lesson_id=?');$get->execute([$row['id']]);$row['stage_ids']=array_values(array_intersect(array_map('intval',$get->fetchAll(PDO::FETCH_COLUMN)),$allowed));$row['content_blocks']=json_decode($row['content_json']??'[]',true);unset($row['content_json']);}respond(200,['lessons'=>$rows]);}
 if($action==='subjects'){$q=$db->prepare("SELECT DISTINCT s.id,s.school_id,s.name FROM subjects s JOIN lessons l ON l.subject_id=s.id JOIN lesson_stages ls ON ls.lesson_id=l.id WHERE s.school_id=? AND ls.stage_id IN ($slots) ORDER BY s.id");$q->execute(array_merge([$sid],$allowed));respond(200,['subjects'=>$q->fetchAll(PDO::FETCH_ASSOC)]);}
}
if(!$isAdmin)respond(403,['error'=>'Недостаточно прав']);

if($action==='members'){
 if($method==='GET'){
  $sid=numericId($_GET['school_id']??'');exists($db,'schools',$sid);
  $q=$db->prepare('SELECT m.id,m.user_id,m.current_stage_id,m.stage_access_override,u.username FROM school_memberships m JOIN users u ON u.id=m.user_id WHERE m.school_id=? ORDER BY u.username');$q->execute([$sid]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
  $rolesQ=$db->prepare('SELECT role_id FROM membership_roles WHERE membership_id=?');foreach($rows as &$row){$rolesQ->execute([$row['id']]);$row['role_ids']=array_map('intval',$rolesQ->fetchAll(PDO::FETCH_COLUMN));}unset($row);respond(200,['members'=>$rows]);
 }
 if($method==='PUT'){
  $body=json_decode(file_get_contents('php://input'),true);if(!is_array($body))respond(400,['error'=>'Некорректный JSON']);$id=numericId($body['id']??'');$q=$db->prepare('SELECT school_id FROM school_memberships WHERE id=?');$q->execute([$id]);$sid=(int)$q->fetchColumn();if(!$sid)respond(404,['error'=>'Участник не найден']);
  $override=(string)($body['stage_access_override']??'default');if(!in_array($override,['default','none','all'],true))respond(422,['error'=>'Неизвестный режим этапа']);
  $stage=null;if($override==='default'){$stage=numericId($body['current_stage_id']??'');$q=$db->prepare('SELECT 1 FROM stages WHERE id=? AND school_id=?');$q->execute([$stage,$sid]);if(!$q->fetchColumn())respond(422,['error'=>'Этап не принадлежит школе']);}
  $roles=$body['role_ids']??[];if(!is_array($roles)||!count($roles)||count($roles)>30)respond(422,['error'=>'Выберите хотя бы одну роль']);$roles=array_unique(array_map('intval',$roles));foreach($roles as $rid)exists($db,'roles',numericId($rid));
  $db->beginTransaction();try{$db->prepare('UPDATE school_memberships SET current_stage_id=?,stage_access_override=? WHERE id=?')->execute([$stage,$override,$id]);$db->prepare('DELETE FROM membership_roles WHERE membership_id=?')->execute([$id]);$q=$db->prepare('INSERT INTO membership_roles(membership_id,role_id) VALUES(?,?)');foreach($roles as $rid)$q->execute([$id,$rid]);$db->commit();}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}respond(200,['ok'=>true]);
 }
 if($method==='POST'){
  $body=json_decode(file_get_contents('php://input'),true);if(!is_array($body))respond(400,['error'=>'Некорректный JSON']);$sid=numericId($body['school_id']??'');$stage=numericId($body['current_stage_id']??'');$role=numericId($body['role_id']??'');exists($db,'roles',$role);
  $q=$db->prepare('SELECT 1 FROM stages WHERE id=? AND school_id=?');$q->execute([$stage,$sid]);if(!$q->fetchColumn())respond(422,['error'=>'Этап не принадлежит школе']);
  $q=$db->prepare('SELECT id FROM users WHERE username=? COLLATE NOCASE');$q->execute([trim((string)($body['username']??''))]);$uid=(int)$q->fetchColumn();if(!$uid)respond(404,['error'=>'Пользователь не найден']);
  try{$db->prepare('INSERT INTO school_memberships(user_id,school_id,current_stage_id) VALUES(?,?,?)')->execute([$uid,$sid,$stage]);$mid=(int)$db->lastInsertId();$db->prepare('INSERT INTO membership_roles(membership_id,role_id) VALUES(?,?)')->execute([$mid,$role]);}catch(PDOException $e){if(str_contains($e->getMessage(),'UNIQUE'))respond(409,['error'=>'Пользователь уже состоит в школе']);throw $e;}respond(201,['ok'=>true]);
 }
 if($method==='DELETE'){$body=json_decode(file_get_contents('php://input'),true);$id=numericId($body['id']??'');$db->prepare('DELETE FROM school_memberships WHERE id=?')->execute([$id]);respond(200,['ok'=>true]);}
 respond(405,['error'=>'Метод не поддерживается']);
}
if($action==='permissions' && $method==='GET')respond(200,['permissions'=>SCHOOL_PERMISSIONS]);
if($action==='roles'){
 if($method==='GET'){
  $rows=$db->query('SELECT id,name,is_default_student FROM roles ORDER BY name COLLATE NOCASE')->fetchAll(PDO::FETCH_ASSOC);
  $get=$db->prepare('SELECT permission FROM role_permissions WHERE role_id=?');foreach($rows as &$row){$get->execute([$row['id']]);$row['permissions']=$get->fetchAll(PDO::FETCH_COLUMN);}unset($row);respond(200,['roles'=>$rows]);
 }
 if(!in_array($method,['POST','PUT','DELETE'],true))respond(405,['error'=>'Метод не поддерживается']);
 $body=json_decode(file_get_contents('php://input'),true);if(!is_array($body))respond(400,['error'=>'Некорректный JSON']);
 if($method==='DELETE'){
  $id=numericId($body['id']??'');exists($db,'roles',$id);
  $q=$db->prepare('SELECT 1 FROM schools WHERE invite_role_id=? LIMIT 1');$q->execute([$id]);if($q->fetchColumn())respond(409,['error'=>'Роль используется для приглашений. Сначала выберите другую роль в настройках школ.']);
  $q=$db->prepare('SELECT 1 FROM membership_roles WHERE role_id=? LIMIT 1');$q->execute([$id]);if($q->fetchColumn())respond(409,['error'=>'Роль назначена пользователям']);
  $db->prepare('DELETE FROM roles WHERE id=?')->execute([$id]);respond(200,['ok'=>true]);
 }
 $name=nameOf($body);$grants=$body['permissions']??[];if(!is_array($grants)||count($grants)>count(SCHOOL_PERMISSIONS))respond(422,['error'=>'Некорректные права']);$grants=array_values(array_unique($grants));foreach($grants as $permission)if(!is_string($permission)||!array_key_exists($permission,SCHOOL_PERMISSIONS))respond(422,['error'=>'Неизвестное право']);
 if($method==='PUT' && count(array_filter($grants,static fn($p)=>str_ends_with($p,'.manage')))>0){
  $existing=numericId($body['id']??'');$q=$db->prepare('SELECT 1 FROM schools WHERE invite_role_id=? LIMIT 1');$q->execute([$existing]);if($q->fetchColumn())respond(422,['error'=>'Сначала выберите другую роль для приглашений в настройках школ']);
 }
 $db->beginTransaction();try{
  if($method==='POST'){$db->prepare('INSERT INTO roles(name) VALUES(?)')->execute([$name]);$id=(int)$db->lastInsertId();}
  else{$id=numericId($body['id']??'');exists($db,'roles',$id);$db->prepare('UPDATE roles SET name=? WHERE id=?')->execute([$name,$id]);$db->prepare('DELETE FROM role_permissions WHERE role_id=?')->execute([$id]);}
  $stmt=$db->prepare('INSERT INTO role_permissions(role_id,permission) VALUES(?,?)');foreach($grants as $permission)$stmt->execute([$id,$permission]);$db->commit();
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();if($e instanceof PDOException&&str_contains($e->getMessage(),'UNIQUE'))respond(409,['error'=>'Роль с таким названием уже существует']);throw $e;}
 respond($method==='POST'?201:200,['ok'=>true,'id'=>$id]);
}
if($action==='school_access'){
 if($method==='GET'){$id=numericId($_GET['school_id']??'');$q=$db->prepare('SELECT id,stage_access_mode,invite_role_id FROM schools WHERE id=?');$q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!$row)respond(404,['error'=>'Школа не найдена']);respond(200,['settings'=>$row]);}
 if($method!=='PUT')respond(405,['error'=>'Метод не поддерживается']);
 $body=json_decode(file_get_contents('php://input'),true);if(!is_array($body))respond(400,['error'=>'Некорректный JSON']);
 $id=numericId($body['school_id']??'');exists($db,'schools',$id);$mode=$body['stage_access_mode']??'';if(!in_array($mode,['current','previous','all'],true))respond(422,['error'=>'Неизвестный режим доступа']);
 $role=numericId($body['invite_role_id']??'');exists($db,'roles',$role);
 $q=$db->prepare("SELECT 1 FROM role_permissions WHERE role_id=? AND permission IN ('roles.manage','users.manage','students.manage','schools.manage','stages.manage','subjects.manage','lessons.manage') LIMIT 1");$q->execute([$role]);if($q->fetchColumn())respond(422,['error'=>'Роль приглашения не должна иметь управляющих прав']);
 $db->prepare('UPDATE schools SET stage_access_mode=?,invite_role_id=? WHERE id=?')->execute([$mode,$role,$id]);respond(200,['ok'=>true]);
}
if($method==='GET'){
if($action==='schools')respond(200,['schools'=>$db->query("SELECT s.id,s.name,s.description,CASE m.stage_access_override WHEN 'all' THEN 'Все этапы' WHEN 'none' THEN 'Нет этапа' ELSE st.name END AS current_stage_name FROM schools s LEFT JOIN school_memberships m ON m.school_id=s.id AND m.user_id=" . (int)$adminId . " LEFT JOIN stages st ON st.id=m.current_stage_id AND st.school_id=s.id ORDER BY s.name COLLATE NOCASE")->fetchAll(PDO::FETCH_ASSOC)]);
if($action==='stages'){$school=numericId($_GET['school_id']??'');exists($db,'schools',$school);$q=$db->prepare('SELECT id,school_id,name,position,invite_code FROM stages WHERE school_id=? ORDER BY position,id');$q->execute([$school]);respond(200,['stages'=>$q->fetchAll(PDO::FETCH_ASSOC)]);}
if($action==='subjects'){$school=numericId($_GET['school_id']??'');exists($db,'schools',$school);$s=$db->prepare('SELECT id,school_id,name FROM subjects WHERE school_id=? ORDER BY id');$s->execute([$school]);respond(200,['subjects'=>$s->fetchAll(PDO::FETCH_ASSOC)]);}
if($action==='lessons'){$subject=numericId($_GET['subject_id']??'');exists($db,'subjects',$subject);$s=$db->prepare('SELECT l.id,l.subject_id,l.name,l.content_json,(SELECT json_group_array(stage_id) FROM lesson_stages WHERE lesson_id=l.id) AS stage_ids FROM lessons l WHERE l.subject_id=? ORDER BY l.id');$s->execute([$subject]);$rows=$s->fetchAll(PDO::FETCH_ASSOC);foreach($rows as &$row){$row['stage_ids']=json_decode($row['stage_ids'],true);$row['content_blocks']=json_decode($row['content_json']??'[]',true);unset($row['content_json']);}respond(200,['lessons'=>$rows]);}
}
if(!in_array($method,['POST','PUT','DELETE'],true))respond(405,['error'=>'Метод не поддерживается']);
$body=json_decode(file_get_contents('php://input'),true);if(!is_array($body))respond(400,['error'=>'Некорректный JSON']);
if($action==='schools'){
 if($method==='POST'){$name=nameOf($body);$description=trim((string)($body['description']??''));if(mb_strlen($description)>500)respond(422,['error'=>'Описание не длиннее 500 символов']);try{$s=$db->prepare('INSERT INTO schools(name,description) VALUES(?,?)');$s->execute([$name,$description]);}catch(PDOException $e){if((string)$e->getCode()==='23000')respond(409,['error'=>'Школа с таким названием уже существует']);throw $e;}respond(201,['id'=>(int)$db->lastInsertId()]);}
 if($method==='PUT'){$id=numericId($body['id']??'');exists($db,'schools',$id);$name=nameOf($body);$description=trim((string)($body['description']??''));if(mb_strlen($description)>500)respond(422,['error'=>'Описание не длиннее 500 символов']);try{$s=$db->prepare('UPDATE schools SET name=?,description=? WHERE id=?');$s->execute([$name,$description,$id]);}catch(PDOException $e){if((string)$e->getCode()==='23000')respond(409,['error'=>'Школа с таким названием уже существует']);throw $e;}respond(200,['ok'=>true]);}
}
if($action==='stages'){
 if($method==='POST'){$school=numericId($body['school_id']??'');exists($db,'schools',$school);$pos=(int)$db->query('SELECT COALESCE(MAX(position),0)+1 FROM stages WHERE school_id='.$school)->fetchColumn();$q=$db->prepare('INSERT INTO stages(school_id,name,position,invite_code) VALUES(?,?,?,?)');$q->execute([$school,nameOf($body),$pos,newStageCode($db)]);respond(201,['id'=>(int)$db->lastInsertId()]);}
 $id=numericId($body['id']??'');exists($db,'stages',$id);
 if($method==='PUT'){$q=$db->prepare('UPDATE stages SET name=? WHERE id=?');$q->execute([nameOf($body),$id]);respond(200,['ok'=>true]);}
 if($method==='DELETE'){$q=$db->prepare('DELETE FROM stages WHERE id=?');$q->execute([$id]);respond(200,['ok'=>true]);}
}
function updateLessonStages(PDO $db,int $lessonId,array $body): void {
 $ids=$body['stage_ids']??[];if(!is_array($ids)||count($ids)>100)respond(422,['error'=>'Некорректный список этапов']);
 $subject=$db->prepare('SELECT school_id FROM subjects WHERE id=(SELECT subject_id FROM lessons WHERE id=?)');$subject->execute([$lessonId]);$school=(int)$subject->fetchColumn();
 $ids=array_values(array_unique(array_map(fn($id)=>numericId($id),$ids)));
 $check=$db->prepare('SELECT 1 FROM stages WHERE id=? AND school_id=?');foreach($ids as $id){$check->execute([$id,$school]);if(!$check->fetchColumn())respond(422,['error'=>'Этап не принадлежит школе предмета']);}
 $q=$db->prepare('DELETE FROM lesson_stages WHERE lesson_id=?');$q->execute([$lessonId]);$q=$db->prepare('INSERT INTO lesson_stages(lesson_id,stage_id) VALUES(?,?)');foreach($ids as $id)$q->execute([$lessonId,$id]);
}
if(in_array($action,['subjects','lessons'],true)){
 $table=$action;$parent=$action==='subjects'?'school_id':'subject_id';$parentTable=$action==='subjects'?'schools':'subjects';
 if($method==='POST'){$pid=numericId($body[$parent]??'');exists($db,$parentTable,$pid);$blocks=$action==='lessons'?validatedLessonBlocks($body['content_blocks']??[]):'[]';
 $sql=$action==='lessons'?"INSERT INTO lessons ($parent,name,content_json) VALUES(?,?,?)":"INSERT INTO subjects ($parent,name) VALUES(?,?)";
 $s=$db->prepare($sql);$values=[$pid,nameOf($body)];if($action==='lessons')$values[]=$blocks;$s->execute($values);$newId=(int)$db->lastInsertId();if($action==='lessons'){ $db->beginTransaction();updateLessonStages($db,$newId,$body);$db->commit();}respond(201,['id'=>$newId]);}
 $id=numericId($body['id']??'');exists($db,$table,$id);
 if($method==='PUT'){$db->beginTransaction();$blocks=$action==='lessons'?validatedLessonBlocks($body['content_blocks']??[]):'[]';
 $sql=$action==='lessons'?"UPDATE lessons SET name=?,content_json=? WHERE id=?":"UPDATE subjects SET name=? WHERE id=?";
 $s=$db->prepare($sql);$values=[nameOf($body)];if($action==='lessons')$values[]=$blocks;$values[]=$id;$s->execute($values);if($action==='lessons')updateLessonStages($db,$id,$body);$db->commit();respond(200,['ok'=>true]);}
 if($method==='DELETE'){$s=$db->prepare("DELETE FROM $table WHERE id=?");$s->execute([$id]);respond(200,['ok'=>true]);}
}
respond(405,['error'=>'Действие не поддерживается']);
}catch(Throwable $e){error_log($e->getMessage());respond(500,['error'=>'Ошибка сервера']);}
