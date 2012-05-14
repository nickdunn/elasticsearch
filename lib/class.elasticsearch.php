<?php

function __autoload_elastica ($class) {
	$path = str_replace('_', '/', $class);
	$load = EXTENSIONS . '/elasticsearch/lib/Elastica/lib/' . $path . '.php';
	if (file_exists($load)) require_once($load);
}
spl_autoload_register('__autoload_elastica');

require_once(TOOLKIT . '/class.sectionmanager.php');

Class ElasticSearch {
	
	public static $client = null;
	public static $index = null;
	public static $types = array();
	public static $mappings = array();
	
	public static function init($host='', $index_name='', $username='', $password='') {
		
		if(self::$client !== NULL && self::$index !== NULL) return;
		
		$config = Symphony::Engine()->Configuration()->get('elasticsearch');
		
		if(empty($host)) $host = $config['host'];
		if(empty($index_name)) $index_name = $config['index-name'];
		if(empty($username)) $username = $config['username'];
		if(empty($password)) $password = $config['password'];
		
		if(empty($host)) {
			throw new Exception('ElasticSearch "host" not set in configuration.');
		}
		
		if(empty($index_name)) {
			throw new Exception('ElasticSearch "index-name" not set in configuration.');
		}
		
		try {
			$client = new Elastica_Client(array('url' => $host));
			if(!empty($username) && !empty($password)) {
				$client->addHeader('Authorization', 'Basic ' . base64_encode($username . ':' . $password));
			}
			$client->getStatus();
		} catch (Exception $e) {
			throw new Exception('ElasticSearch client: ' . $e->getMessage());
		}
		
		$index = $client->getIndex($index_name);
		
		if($auto_create_index) {
			//if(!$index->exists()) $index->create(array(), TRUE);
		}
		
		self::$client = $client;
		self::$index = $index;
	}
	
	public static function flush() {
		self::$client = NULL;
		self::$index = NULL;
		self::$types = array();
		self::$mappings = array();
	}
	
	public static function getIndex() {
		return self::$index;
	}
	
	public static function getClient() {
		return self::$client;
	}
	
	public static function getTypeByHandle($handle) {
		if(in_array($handle, self::$types)) {
			return self::$types[$handle];
		}
		
		self::$types = self::getAllTypes();
		return self::$types[$handle];
	}
	
	public static function getAllTypes() {
		self::init();
		
		if(count(self::$types) > 0) return self::$types;
		
		$sm = new SectionManager(Symphony::Engine());
		
		$get_mappings = self::getIndex()->request('_mapping', Elastica_Request::GET, array());
		$all_mappings = $get_mappings->getData();
		self::$mappings = reset($all_mappings);
		
		$types = array();
		foreach($sm->fetch() as $section) {
			
			$elasticsearch_mapping_file = sprintf('%s/elasticsearch/mappings/%s.json', WORKSPACE, preg_replace('/-/', '_', $section->get('handle')));
			$symphony_mapping_file = sprintf('%s/elasticsearch/mappings/%s.php', WORKSPACE, preg_replace('/-/', '_', $section->get('handle')));
			
			// no mapping, no valid type
			if(!file_exists($elasticsearch_mapping_file)) continue;
			
			require_once($symphony_mapping_file);
			$symphony_mapping_classname = sprintf('elasticsearch_%s', preg_replace('/-/', '_', $section->get('handle')));
			$symphony_mapping_class = new $symphony_mapping_classname;
			
			$elasticsearch_mapping_json = file_get_contents($elasticsearch_mapping_file);
			$elasticsearch_mapping = json_decode($elasticsearch_mapping_json, FALSE);
			$mapped_fields = $elasticsearch_mapping->{$section->get('handle')}->properties;
			
			// invalid JSON
			if(!$mapped_fields) throw new Exception('Invalid mapping JSON for ' . $section->get('handle'));
			
			$fields = array();
			foreach($mapped_fields as $field => $mapping) $fields[] = $field;
			
			$type = self::getIndex()->getType($section->get('handle'));
			if(!isset(self::$mappings[$section->get('handle')])) $type = NULL;
			
			$types[$section->get('handle')] = (object)array(
				'section' => $section,
				'fields' => $fields,
				'type' => $type,
				'mapping_json' => $elasticsearch_mapping_json,
				'mapping_class' => $symphony_mapping_class
			);
		}
		
		self::$types = $types;
		return $types;
	}
	
	public static function createType($handle) {
		self::init();
		
		$local_type = self::getTypeByHandle($handle);
		$mapping = json_decode($local_type->mapping_json, TRUE);
		
		$type = new Elastica_Type(self::getIndex(), $handle);
		
		$type_mapping = new Elastica_Type_Mapping($type);
		foreach($mapping[$handle] as $key => $value) {
			$type_mapping->setParam($key, $value);
		}
		
		$type->setMapping($type_mapping);
		self::getIndex()->refresh();
	}
	
	public static function indexEntry($entry, $section=NULL) {
		self::init();
		
		if(!$entry instanceOf Entry) {
			// build the entry
			$em = new EntryManager(Symphony::Engine());
			$entry = reset($em->fetch($entry));
		}
		
		if(!$section instanceOf Section) {
			// build section
			$sm = new SectionManager(Symphony::Engine());
			$section = $sm->fetch($entry->get('section_id'));
		}
		
		$type = self::getTypeByHandle($section->get('handle'));
		if(!$type || !$type->type) return;

		// build an array of entry data indexed by field handles
		$data = array();
		
		foreach($section->fetchFields() as $f) {
			//if(!in_array($f->get('element_name'), $type->fields)) continue;
			$data[$f->get('element_name')] = $entry->getData($f->get('id'));
		}
		
		$data = $type->mapping_class->mapData($data, $entry);
		
		if($data) {
			$document = new Elastica_Document($entry->get('id'), $data);
			try {
				$doc = $type->type->addDocument($document);
			} catch(Exception $ex) {
				
			}
		} else {
			self::deleteEntry($entry, $section);
		}
		
		self::getIndex()->refresh();
		
	}
	
	public static function deleteEntry($entry, $section=NULL) {
		
		if(!$entry instanceOf Entry) {
			// build the entry
			$em = new EntryManager(Symphony::Engine());
			$entry = reset($em->fetch($entry));
		}
		
		if(!$section instanceOf Section) {
			// build section
			$sm = new SectionManager(Symphony::Engine());
			$section = $sm->fetch($entry->get('section_id'));
		}
		
		$type = self::getTypeByHandle($section->get('handle'));
		if(!$type) return;

		try {
			$type->type->deleteById($entry->get('id'));
		} catch(Exception $ex) { }
		
		self::getIndex()->refresh();
	}
	
	/* 
	Inspired by Clinton Gormley's perl client
		https://github.com/clintongormley/ElasticSearch.pm/blob/master/lib/ElasticSearch/Util.pm
	Full list of query syntax
		http://lucene.apache.org/core/old_versioned_docs/versions/3_0_0/queryparsersyntax.html
	*/
	public static function filterKeywords($keywords) {
		// strip tags, should aid against XSS
		$keywords = strip_tags($keywords);
		// remove characters from start/end
		$keywords = trim($keywords, '-+ ');
		// append leading space for future matching
		$keywords = ' ' . $keywords;
		// remove wilcard `*` and `?` and fuzzy `~`
		$keywords = preg_replace("/\*|\?|\~/", "", $keywords);
		// remove range syntax `{}`
		$keywords = preg_replace("/\{|\}/", "", $keywords);
		// remove group `()` and`[]` chars
		$keywords = preg_replace("/\(|\)|\[|\]/", "", $keywords);
		// remove boost `^`
		$keywords = preg_replace("/\^/", "", $keywords);
		// remove not `!`
		$keywords = preg_replace("/\!/", "", $keywords);
		// remove and `&&`
		$keywords = preg_replace("/\&\&/", "", $keywords);
		// remove or `||`
		$keywords = preg_replace("/\|\|/", "", $keywords);
		// remove fields such as `title:`
		$keywords = preg_replace("/([a-zA-Z0-9_-]+\:)/", "", $keywords);
		// remove `-` that don't have spaces before them
		$keywords = preg_replace("/(?<! )-/", "", $keywords);
		// remove the spaces after a + or -
		$keywords = preg_replace("/([+-])\s+/", "", $keywords);
	    // remove multiple spaces
		$keywords = preg_replace("/\s{1,}/", " ", $keywords);
		// remove characters from start/end (again)
		$keywords = trim($keywords, '-+ ');
		// add trailing quotes if missing
		$quotes = substr_count($keywords, '"');
		if($quotes % 2) $keywords .= '"';
		
		return trim($keywords);
	}
	
}