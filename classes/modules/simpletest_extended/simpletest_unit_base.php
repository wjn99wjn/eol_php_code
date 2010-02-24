<?php

require_once(LOCAL_ROOT . '/classes/modules/simpletest/autorun.php');

class SimpletestUnitBase extends UnitTestCase
{
    function setUp()
    {
        $called_class_name = get_class($this);
        echo "UnitTest => ".$called_class_name."\n"; flush();
        
        $this->mysqli =& $GLOBALS['mysqli_connection'];
        $this->mysqli->truncate_tables('test');
        if(isset($this->load_fixtures)) $this->load_fixtures();
    }
    
    function tearDown()
    {
        unset($this->fixtures);
        //shell_exec("rm -fdr ".DOC_ROOT."temp/*");
    }
    
    function load_fixtures()
    {
        $this->fixtures = Functions::load_fixtures('test');
    }
}

?>