<?php
class elasticsearch_articles {
	
	public function mapData(Array $data, Entry $entry) {
		$json = array();

		// each of your mapped fields should be populated here. $json is the
		// JSON object that is sent to ES, while $data is an array of the Entry's
		// raw data. You also have access to the Entry itself ($entry) if needed
		
		// BOOST this multiplies the "_score" ES assigns to a matched document
		// increase here to boost documents in this section above others
		$json['_boost'] = 1;
		
		// FILTERING
		// to filter out entries, check the data and simply retun false from this
		// method. for example if a Published checkbox must be ticked, check here
		if($data['published']['value'] !== 'yes') return;
		
		// EXAMPLE text input field
		$json['title'] = $data['title']['value'];
		
		// EXAMPLE date field (Symphony's dates are already in the correct format)
		$json['title'] = $data['title']['value'];
		
		// EXAMPLE file upload field. if the field is optional, check that the field
		// has a value, or perhaps check that the file exists before sending it
		// NOTE: this requires you have the "attachments" plugin installed in ES
		$json['upload'] = base64_encode(file_get_contents($data['my-upload-field']['file']));
		
		return $json;
	}
	
}