<?
define('WPU_TEST_ROOT_PATH', realpath(dirname(dirname(__FILE__))));
define('IN_PHPBB', true);
define('ABSPATH', true);
		
		
class WP_United_ParseTest extends PHPUnit_Framework_TestCase {

	public function setup() {
		
	}
	
	
	public function teardown() {}
	
	
	
	
	
	public function test_phpbb() {
		
		$files = @$this->deep_glob(WPU_TEST_ROOT_PATH . '/phpbb3\ MOD/root/', '*.php', 0, -1);
		$successLooksLike = sizeof($files);
		$successIs = 0;
		


		ob_start();
		foreach($files as $file) {
			if(stristr($file, 'style-fixer.php') === false) {
				require_once($file);
			}
			$successIs++;
		}
		ob_end_clean();
		$this->assertTrue($successIs === $successLooksLike);
		
	}

	public function test_wp() {
		
		$files = @$this->deep_glob(WPU_TEST_ROOT_PATH . '/WordPress\ Plugin/', '*.php', 0, -1);
		$successLooksLike = sizeof($files);
		$successIs = 0;
		
		ob_start();
		foreach($files as $file) { 
			if(
				(stristr($file, 'wp-united.php') === false) &&
				(stristr($file, 'settings-panel.php') === false)
			) {
				require_once($file);
			}
			$successIs++;
		}
		ob_end_clean();
		
		$this->assertTrue($successIs === $successLooksLike);
		
	}
	
	private function deep_glob($path, $pattern = '*', $flags = 0, $depth = 0) {
        $matches = array();
        $folders = array(rtrim($path, DIRECTORY_SEPARATOR));
       
        while($folder = array_shift($folders)) {
            $matches = array_merge($matches, glob($folder.DIRECTORY_SEPARATOR.$pattern, $flags));
            if($depth != 0) {
                $moreFolders = glob($folder.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR);
                $depth   = ($depth < -1) ? -1: $depth + count($moreFolders) - 2;
                $folders = array_merge($folders, $moreFolders);
            }
        }
        return $matches;
    }

}