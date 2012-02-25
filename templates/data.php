<?php
class ElasticSearch_%s {
	
	public $filters = array(
		%s
	);
	
	public function getData(Entry $entry) {
		$data = array();
		$fields = 
		$data['title'] = $entry->getData();
		return json_encode($data, JSON_FORCE_OBJECT);
	}
	
}