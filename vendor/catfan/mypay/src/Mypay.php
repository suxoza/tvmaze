<?php



class MyPayModel{
	private $DB;
	public function __construct($db){
		$this->DB = $db;
	}
	public function pre($vls){
		echo "<pre>";
		print_r($vls);
		echo "</pre>";
	}


	public function Log($message = '', $get = []){
		$ip = isset($_SERVER['REMOTE_ADDR'])?$_SERVER['REMOTE_ADDR']:'';
		$get = 
		$get = json_encode($get);
		$this->DB->insert("pay_log",[
			'ip' 	=> $ip,
			'req'   => $get,
			'log'   => $message
		]);
	}

	public function GetError($OrderTitle){
		$s = $this->DB->select("pay_error_codes", ["id", "descr"], ["title" => $OrderTitle]);
		return $s?$s[0]:[];
	}
}

class Mypay{
	private $UserName;
	private $Password;
	private $SecKey;
	private $Data;
	private $Model;
	private $Request;




	function __construct($db, $userName, $password, $secret){
		$this->UserName = $userName;
		$this->Password = $password;
		$this->SecKey = $secret;
		$this->MinAmount  = 0.5;
		$this->Request    = $_GET;
		$this->Model = new MyPayModel($db);
		
		$this->SetData();
		
	}

	public function SetData(){
		try{
			$NewData = [];
			$Data    = ['OP', 'USERNAME', 'PASSWORD', 'CUSTOMER_ID', 'SERVICE_ID', 'PAY_AMOUNT', 'PAY_SRC', 'PAYMENT_ID', 'EXTRA_INFO', 'HASH_CODE'];

			foreach($Data as $Key => $Val)
				$NewData[$Val] = isset($this->Request[$Val])?$this->Request[$Val]: '';
			
			$this->Data = $NewData;
			$this->Init();

		}catch(Exception $ex){
			echo $ex->getMessage();
		}

	}


	public function Init(){
		$this->Model->Log();
		
		$this->CheckData();

		$array = [
			'debt' => function(){
				echo "not implemented";
			},
			'verify' => function(){
				$this->CheckCustomer(true);
			},
			'pay' => function(){
					$this->CheckCustomer();
					$this->CheckAmount();
					$this->CheckPayment();

					$Pay = $this->Model->AddUserAmount($this->Data);
					
					if($Pay['StatusID'] == 0)
						$this->ResponseToService('PAYMENT_UNABLE');
					else
						$this->ResponseToService('OK', array('receipt-id' => $Pay['ReceiptID']));
			},
			'ping' => function(){
				$this->ResponseToService('OK');
			},

		];
		
		if(array_key_exists($this->Data['OP'], $array))
			$array[$this->Data['OP']]();
		
	}

	public function CheckPayment(){
		if($this->Model->CheckPayment($this->Data['PAYMENT_ID'], $this->PayBoxID)){
			$this->ResponseToService('UNUNIQUE_PAYMENT_ID');
		}
	}


	public function CheckData(){
		if($this->Data['USERNAME'] != $this->UserName || $this->Data['PASSWORD'] != $this->Password)
			$this->ResponseToService('USERNAME_INCORRECT');
		

		if(strlen($this->Data['HASH_CODE']) != 32)
			$this->ResponseToService('PARAMETER_VALUE_INCORRECT');
		

		$MD5 = md5($this->Data['OP'] . $this->Data['USERNAME'] . $this->Data['PASSWORD'] . 
				   $this->Data['CUSTOMER_ID'] . $this->Data['SERVICE_ID'] . $this->Data['PAY_AMOUNT'] .
				   $this->Data['PAY_SRC'] . $this->Data['PAYMENT_ID'] . $this->Data['EXTRA_INFO'] . $this->SecKey);
		
		if(strtoupper($this->Data['HASH_CODE']) != strtoupper($MD5))
			$this->ResponseToService('HASH_CODE_INCORRECT');
		
	}

	public function CheckCustomer($Resp = false){
		$User = $this->Model->CheckUser($this->Data['CUSTOMER_ID']);
		
		if(empty($User)){
			$this->ResponseToService('USER_NOT_FOUND');
		}
		elseif($Resp == true){
			$this->ResponseToService('OK', array('fullname' => $User));
		}
	}

	public function CheckAmount(){
		if((float)$this->Data['PAY_AMOUNT'] != $this->Data['PAY_AMOUNT'] || $this->Data['PAY_AMOUNT'] / 100 < $this->MinAmount){
			$this->ResponseToService('AMOUNT_INCORRECT');
		}
	}

	public function ResponseToService($errorTitle, $data = array()){
		$error   = $this->Model->GetError($errorTitle);

		$dataStr = '';

		if(!empty($data))
			foreach($data as $key => $value)
				$DataStr .= '<' . $key . '>' . $value . '</' . $key . '>';
			
		

		

		die(
		'<?xml version="1.0" encoding="UTF-8"?>
		<pay-response>
			<status code="' . $error['id'] . '">' . $error['descr'] . '</status>
			<timestamp>' . time() . '</timestamp>
			' . $dataStr . '
		</pay-response>');
	}
}