<?php
namespace lib;

class Template {

	static private function safeName($name, $default='index'){
		$name = trim((string)$name);
		if($name === '') $name = $default;
		if(!preg_match('/^[a-zA-Z0-9_]{1,64}$/', $name)) exit('error');
		return $name;
	}

	static private function safeTemplate($template){
		$template = trim((string)$template);
		if($template === '') $template = 'default';
		if(!preg_match('/^[a-zA-Z0-9_]{1,64}$/', $template)) return 'default';
		return $template;
	}

	static private function ensureInside($file, $base){
		$real = realpath($file);
		$root = realpath($base);
		if($real === false || $root === false || strpos($real, $root.DIRECTORY_SEPARATOR) !== 0) exit('Template path error');
		return $real;
	}

	static public function getList(){
		$dir = TEMPLATE_ROOT;
		$dirArray[] = NULL;
		if (false != ($handle = opendir($dir))) {
			$i = 0;
			while (false !== ($file = readdir($handle))) {
				if ($file != "." && $file != ".." && strpos($file, ".")===false) {
					$dirArray[$i] = $file;
					$i++;
				}
			}
			closedir($handle);
		}
		return $dirArray;
	}

	static public function load($name = 'index'){
		global $conf;
		$template = self::safeTemplate($conf['template']?$conf['template']:'default');
		$name = self::safeName($name);
		$filename = TEMPLATE_ROOT.$template.'/'.$name.'.php';
		$filename_default = TEMPLATE_ROOT.'default/'.$name.'.php';
		if(file_exists($filename)){
			define("INDEX_ROOT",TEMPLATE_ROOT.$template.'/');
			define("STATIC_ROOT",'/template/'.$template.'/assets/');
			return self::ensureInside($filename, TEMPLATE_ROOT.$template);
		}elseif(file_exists($filename_default)){
			define("INDEX_ROOT",TEMPLATE_ROOT.'default/');
			define("STATIC_ROOT",'/template/default/assets/');
			return self::ensureInside($filename_default, TEMPLATE_ROOT.'default');
		}else{
			exit('Template file not found');
		}
	}

	static public function loadDoc($name = 'index'){
		$name = self::safeName($name);
		$filename = TEMPLATE_ROOT.'default/doc/'.$name.'.php';
		if(file_exists($filename)){
			return self::ensureInside($filename, TEMPLATE_ROOT.'default/doc');
		}else{
			exit('Document file not found');
		}
	}

	static public function exists($template){
		$filename = TEMPLATE_ROOT.$template.'/index.php';
		if(file_exists($filename)){
			return true;
		}else{
			return false;
		}
	}
}
