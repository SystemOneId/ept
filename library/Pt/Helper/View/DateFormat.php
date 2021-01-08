<?php
class  Pt_Helper_View_DateFormat extends Zend_View_Helper_Abstract {

	public function dateFormat($dateIn){
		
		$file = APPLICATION_PATH . DIRECTORY_SEPARATOR . "configs" . DIRECTORY_SEPARATOR . "config.ini";

		// $config = new Zend_Config_Ini($file, APPLICATION_ENV, array('allowModifications'=>true, 'nestSeparator'=>"#"));
		$config = new Zend_Config_Ini($file, APPLICATION_ENV, array('allowModifications' => false));

		$formatDate= $config->participant->dateformat;

		if($dateIn == null || $dateIn == "" || $dateIn =="0000-00-00"){
			return '';
		}
		else{
			$dateArray = explode('-', $dateIn);
			$newDate = $dateArray[2]. "-";
			$monthsArray = array('Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec');
			$mon = $monthsArray[$dateArray[1]-1];
			if($formatDate == 'dd-M-yy') {
                return  date_format(date_create($dateIn),'d-M-Y');
                //$newDate . $mon . "-" . $dateArray[0];
                // The above active line always meets the requirement if you have any suggestion please let me know so that i will improve the code quality.
            }
			else
				return   $mon ."-" . $newDate . $dateArray[0];
		}
	}
	public function getDateFormat(){

		$file = APPLICATION_PATH . DIRECTORY_SEPARATOR . "configs" . DIRECTORY_SEPARATOR . "config.ini";
			
		// $config = new Zend_Config_Ini($file, APPLICATION_ENV, array('allowModifications'=>true, 'nestSeparator'=>"#"));
		$config = new Zend_Config_Ini($file, APPLICATION_ENV, array('allowModifications' => false));
			
		return $config->participant->dateformat;
	}

}