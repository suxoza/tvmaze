<?php


header("Content-type: text/html; Charset=utf-8");
error_reporting(E_ALL);
date_default_timezone_set("Asia/Tbilisi");   
ini_set("display_errors", 0);

require 'vendor/autoload.php';
use Medoo\Medoo;


/*

	how to run:
		browser:
			http:{url}/?methodName={method}
		terminal:
			cd {dir} && php index.php -m {method}

		examples:
			browser:
				http://tvmaze.loc/?methodName=getAllShows
			terminal:
				cd /var/www/tvmaze && php index.php -m getAllShows

		crontab example: //every minute
		* * * * *   {user} cd /var/www/tvmaze && /usr/bin/php index.php -m getAllShows

	available methods: 
		1)  getAllShows - 
				NOTE:
					PHP don`t have thread or proceses so, script will run very long :)

				steps:
					check all shows:
						for each show-item:
							if don`t exists
								insert show-item

								episodes:
									insert
								casts:
									insert
								gender:
									insert

							else
								if changed
									modify show-item

									episodes:
										if new one add
										else
											delete or add new one etc...
									same for casts and gender	

		2)  countItems - not implemented yet

*/


final class Main{

	public $DB;
	private static $instance;
	public static $logContainer = [];
	private function __construct(){
		echo "init database!\n";
  		$this->DB = new Medoo([
		    'database_type' => 'mysql',
		    'database_name' => 'tvmaze',
		    'server' 		=> 'localhost',
		    'username' 		=> 'tvmaze',
		    'password' 		=> 'tvmaze',
		    'charset'  		=> 'utf8mb4'
		]);
  	}

  	public static function Log(string $key) : void {
  		if(!array_key_exists($key, self::$logContainer))
  	 		self::$logContainer[$key] = 1;
  	 	else
  	 		self::$logContainer[$key]++;
  	}

	public static function getInstance() : Main {
    	if(self::$instance == null)
    		self::$instance = new Main();
    	return self::$instance;
  	}

  	public static function pre($string) : void {
		echo "<pre>";
		print_r($string);
		echo "</pre>";
	}

}

abstract class Helper{

	public $data = ['status' => 1, 'message' => ''];

	protected $allShows = "http://api.tvmaze.com/updates/shows";
	protected $singleShow = "http://api.tvmaze.com/shows/";// + showID
	protected static $singleton;

	public function __construct(){
		self::$singleton = Main::getInstance();
		$this->genres = new RelationTable("shows_genres", "genres_id", "show_id");
		$this->casts = new RelationTable("shows_casts", "cast_id", "show_id");
	}

	protected function init() : void {

		try{
			
			$data = [];
			
			if(PHP_SAPI == 'cli'){
				$v = getopt("m:methodName");
				$methodName = $v['m']??'';
			}else
				$methodName = $this->params['methodName']??'';
			
			if(!$methodName)
				throw new Exception("required parameters is not present!");
			
			$reflection = new ReflectionClass(get_class($this));
			if($reflection->hasMethod($methodName))
                $reflection->getMethod($methodName)->invoke($this, $this->params);
            else
            	throw new Exception("Not implemented!");
		
		}catch(Exception $ex){
			$this->data['status'] = 0;
			$this->data['message'] = $ex->getMessage();
			Main::pre($this->data);
		}finally{
			Main::pre(Main::$logContainer);
		}	
	}

	protected function toJSON($url){
		return json_decode(file_get_contents($url));
	}
}


class Server extends Helper {
	public function __construct(){
		parent::__construct();
		$this->params = $_GET;
		$this->init();
	}

	
	public function getAllShows() : void {
		$data = @$this->toJSON($this->allShows);
		$data = $data?$data:[];
		$iter = 0;
		foreach($data as $tvmazeID => $timestamp){
			$this->searchInShows($tvmazeID, $timestamp);


			/*
			if($iter && !($iter % 100)){
				// sleep in every 1 / 4 second
				usleep(250000);
				//big int need more memory, so...
				$iter = 0;
			}
			*/
			if($iter > 10)break;
			$iter++;

		}
	}

	public function countItems() : void {
		$sql = "";
		throw new Exception("not implemented yet");
	}


	private function searchInShows(int $tvmazeID, int $timestamp) : void {
		$select = self::$singleton->DB->get("shows", "*" , ["tvmaze_id" => $tvmazeID]);
		$showID = 0;
		
		if(!$select){
			Main::Log("New items into: show");
			$showID = $this->createShow($tvmazeID);
			$this->getEpisodes($showID, $tvmazeID, false);
			$this->getCasts($showID, $tvmazeID, false);
		}else{
			if($select['showUpdateDate'] < $timestamp){
				Main::Log("if show exists in db but time was different, need update recursive");
				$showID = $select['id'];
				$this->updateShow($select, $tvmazeID);
				$this->getEpisodes($showID, $tvmazeID, true);
				$this->getCasts($showID, $tvmazeID, true);
			}
		}
	}

	private function createShow(int $tvmazeID) : int {
		list($newShow, $genres) = $this->getShowAsArray($tvmazeID);
		self::$singleton->DB->insert("shows", $newShow);
		$insertID = self::$singleton->DB->id();
		if($genres)
			$this->genres->relationBuffer($genres, $insertID, false);
		return $insertID;
	}

	private function updateShow(array $currentShow, int $tvmazeID) : void {
		list($newShow, $genres) = $this->getShowAsArray($tvmazeID);

		/*
			check if changes required
		*/
		$needUpdate = false;
		foreach($newShow as $key => $value)
			if($value != $currentShow[$key]){
				$needUpdate = true;
				break;
			}

		/*
				change only modified records
		*/
		if($needUpdate)
			self::$singleton->DB->update('shows', $newShow, ["id" => $currentShow['id']]);
		if($genres)
			$this->genres->relationBuffer($genres, $currentShow['id'], true);
	}

	private function getObjectItem($object, $defaut = ''){
		try{
			return $object;
		}catch(Exception $ex){
			return $defaut;
		}
	}

	private function getShowAsArray(int $tvmazeID) : array {
		$data = @$this->toJSON($this->singleShow.$tvmazeID);
		$data = $data?$data:new stdClass();
		$insertData = [
			'tvmaze_id' 	 => (int)$this->getObjectItem($tvmazeID, 0),
			'imdb_id' 		 => (string)$this->getObjectItem((isset($data->externals->imdb)?$data->externals->imdb:''), ''),
			'description' 	 => trim(strip_tags($this->getObjectItem($data->summary??'', ''))),
			'first_air_year' => (string)$this->getObjectItem($data->premiered??'', ''),
			'trailer'		 => (string)$this->getObjectItem((isset($data->image->original)?$data->image->original:''),''),
			'showUpdateDate' => (int)$this->getObjectItem($data->updated??'', 0)
		];
		$genres = isset($data->genres)?(array)$data->genres:[];
		return [$insertData, $genres];
	}

	private function getCasts(int $showID, int $tvmazeID, bool $updateMode) : void {
		$data = @$this->toJSON($this->singleShow.$tvmazeID."/cast");
		$data = $data?$data:[];
		$data = array_map(function($var){
			return $var->person??new stdClass();
		}, $data);
		
		/*
			get all casts as array | for database
		*/
		$inserts = array_map(function($var){
			return $this->getSingleCast($var);
		}, $data);
		$inserts = $inserts??[];

		if($inserts)
			$this->casts->relationBufferForObject($inserts, $showID, $updateMode);
	}

	private function getSingleCast(object $cast) : array {
		return [
			'cast_id' 		 => (int)$cast->id??0,
			'name'	 		 => (string)$cast->name??'',
			'hero'   		 => (string)$cast->url??'',
			'image'          => isset($cast->image->original)?$cast->image->original:'',
		];
	}

	private function getEpisodes(int $showID, int $tvmazeID, bool $updateMode) : void {
		$data = @$this->toJSON($this->singleShow.$tvmazeID."?embed=episodes");
		$data = $data?$data:[];

		$episodes = $data->_embedded->episodes??new stdClass();

		$this->episodeManipulation = new TableChildManipulation("episodes", "episode_id", "show_id");

		/*
			get all episodes as array | for database
		*/

		$inserts = array_map(function($var) use ($showID, $updateMode){
			if(!$updateMode)
				Main::Log("New items into: episodes");
			return $this->getSingleEpisode($var, $showID);
		}, (array)$episodes);
		$inserts = $inserts??[];

		if($updateMode){
			/*
				if update mode: find all episodes in db if need add new or delete olds, etc...
			*/
			$this->episodeManipulation->updateOrDelete($inserts, $showID);
		}else{
			/*
				if insert mode: insert new ones
			*/
			$this->episodeManipulation->insert($inserts);
		}
	}

	private function getSingleEpisode(object $episode, int $showID) : array {
		return [
			'show_id' 		 => $showID,
			'episode_id'	 => (int)$this->getObjectItem($episode->id,0),
			'episode_name'   => (string)$this->getObjectItem($episode->name,''),
			'season_number'  => (int)$this->getObjectItem($episode->season,0),
			'episode_number' => (int)$this->getObjectItem($episode->number,0),
			'image' 		 => isset($episode->image->original)?$this->getObjectItem($episode->image->original,''):'',
			'summary' 		 => trim(strip_tags($this->getObjectItem($episode->summary,''))),
		];
	}

}


class TableManipulation{

	protected static $singleton;
	public function __construct(string $tableName, string $destColumn, string $whereColumn){
		self::$singleton = Main::getInstance();
		$this->tableName   = $tableName;
		$this->destColumn  = $destColumn;
		$this->whereColumn = $whereColumn; 
	}

	protected function delete(array $deletedIDS) : void {}
	
	public function updateOrDelete(array $array, int $id) : void {

		/*
			get all old records from database
		*/
		$oldArray = self::$singleton->DB->select($this->tableName, $this->destColumn, [$this->whereColumn => $id]);

		/*
			get records which don`t exists in database!
		*/
		$insertIDS = array_filter(array_map(function($var) use ($oldArray){
			if(!in_array($var, $oldArray)){
				return $var;
			}
		}, $array));

		/*
			get records which exists in database but don`t exists in json | they must be deleted
		*/
		$deletedIDS = array_filter(array_map(function($var) use ($array){
			if(!in_array($var, $array)){
				Main::Log("Delete items from: ".$this->tableName);
				return $var;
			}
		}, $oldArray));

		if($deletedIDS) //delete action
			self::$singleton->DB->delete($this->tableName, [$this->destColumn => $deletedIDS]);
		if($insertIDS) //insert action
			$this->insert($insertIDS, $id);
	}

	public function insert(array $array, int $id) : void {
		$relation = [];
		foreach($array as $v)
			$relation[] = [$this->whereColumn => $id, $this->destColumn => $v];
		self::$singleton->DB->insert($this->tableName, $relation);
	}
}

class TableChildManipulation extends TableManipulation{


	/*
		@override
	*/
	public function updateOrDelete(array $array, int $id) : void {

		/*
			get all old records from database
		*/
		$oldArray = self::$singleton->DB->select($this->tableName, $this->destColumn, [$this->whereColumn => $id]);
		
		
		/*
			get records which don`t exists in database!
		*/
		$insertIDS = array_filter(array_map(function($var) use ($oldArray){
			if(!in_array($var['episode_id'], $oldArray)){
				Main::Log("New items into..: ".$this->tableName);
				return $var;
			}
		}, $array));

		$newArrayIDS = array_map(function($var){
			return $var['episode_id'];
		}, $array);

		/*
			get records which exists in database but don`t exists in json | they must be deleted
		*/
		$deletedIDS = array_filter(array_map(function($var) use ($newArrayIDS){
			if(!in_array($var, $newArrayIDS)){
				Main::Log("Delete items from: ".$this->tableName);
				return $var;
			}
		}, $oldArray));

		if($deletedIDS) //delete action
			self::$singleton->DB->delete($this->tableName, [$this->destColumn => $deletedIDS]);
		if($insertIDS) //insert action
			$this->insert($insertIDS, $id);
	}

	/*
		@override
	*/
	public function insert(array $array, int $id = null) : void {
		self::$singleton->DB->insert($this->tableName, $array);
	}
}


class RelationTable{


	private $relationBufferArray = [];
	private $bufferSize = 500; //used for: genres and casts
	private static $instance = null;



	private static $singleton;
  	public function __construct(string $tableName, string $destColumn, string $whereColumn){
  		self::$singleton = Main::getInstance();

  		$this->tableName   = $tableName;
		$this->destColumn  = $destColumn;
		$this->whereColumn = $whereColumn; 

  		$this->relationDeleteORinsert = new TableManipulation($this->tableName, $this->destColumn, $this->whereColumn);
  		//$this->relationDeleteORinsert = new TableManipulation("shows_genres", "genres_id", "show_id");
  	}

	public function relationBufferForObject(array $relation, int $showID, bool $updateMode) : void {

		$concat = function(string $id) use ($showID){
			return $showID.'|'.$id;
		};

		$relationIDS = [];
		foreach($relation as $value){
			/*
				find field in buffer
			*/
			if(in_array($value['name'], $this->relationBufferArray)){
				/*
					check if field exists in buffer
				*/
				$bufferID = array_search($value['name'], $this->relationBufferArray);
			}else{
				/*
					insert field if they don`t exists in buffer yet
				*/
				$bufferID = $this->addNewCast($value);
				$this->relationBufferArray[$bufferID] = $value['name'];
			}
			$relationIDS[] = $bufferID;
			if(!$updateMode)
				Main::Log("New items into.: ".$this->tableName);
		}


		if($updateMode){
			/*
				if update mode: find all relationObject in db if need add new or delete olds, etc...
			*/
			$this->relationDeleteORinsert->updateOrDelete($relationIDS, $showID);
			//$this->showGenreRelationOnUpdate($relationIDS, $showID);
		}else{ 
			/*
				if insert mode: insert new ones
			*/
			$this->relationDeleteORinsert->insert($relationIDS, $showID);
			//$this->insertRelation($relationIDS, $showID);
		}
	
		/*
			clear buffer - ram economy
		*/
		if(count($this->relationBufferArray) > $this->bufferSize)
			$this->relationBufferArray = [];
		
	}	


	public function relationBuffer(array $relation, int $showID, bool $updateMode) : void {

		$relationIDS = [];
		foreach($relation as $value){
			/*
				find field in buffer
			*/
			if(in_array($value, $this->relationBufferArray)){
				/*
					check if field exists in buffer
				*/
				$bufferID = array_search($value, $this->relationBufferArray);
			}else{
				/*
					insert field if they don`t exists in buffer yet
				*/
				$bufferID = $this->addNewGenres($value);
				$this->relationBufferArray[$bufferID] = $value;
			}
			$relationIDS[] = $bufferID;
			if(!$updateMode)
				Main::Log("New items into...: ".$this->tableName);
		}


		if($updateMode){
			/*
				if update mode: find all relationObject in db if need add new or delete olds, etc...
			*/
			$this->relationDeleteORinsert->updateOrDelete($relationIDS, $showID);
			//$this->showGenreRelationOnUpdate($relationIDS, $showID);
		}else{ 
			/*
				if insert mode: insert new ones
			*/
			$this->relationDeleteORinsert->insert($relationIDS, $showID);
			//$this->insertRelation($relationIDS, $showID);
		}
			
		

		/*
			clear buffer - ram economy
		*/
		if(count($this->relationBufferArray) > $this->bufferSize)
			$this->relationBufferArray = [];
		
	}

	private function addNewGenres(string $genre) : int {
		$select = (int)self::$singleton->DB->get("genres", "id", ["genre" => $genre]);
		if($select)return $select;
		Main::Log("New items into: genre");
		self::$singleton->DB->insert("genres", ['genre' => $genre]);
		return self::$singleton->DB->id();
	}

	private function addNewCast(array $cast) : int {
		$select = (int)self::$singleton->DB->get("casts", "cast_id", ["cast_id" => $cast['cast_id']]);
		if($select)return $select;
		Main::Log("New items into: casts");
		self::$singleton->DB->insert("casts", $cast);
		return $cast['cast_id'];
	}

}

new Server();
