# ElasticSearch

* Version: 0.2
* Author: [Nick Dunn](http://nick-dunn.co.uk)
* Build Date: 
* Requirements: Symphony 2.2

## Description
The ElasticSearch extension integrates Symphony with [ElasticSearch](http://www.elasticsearch.org/) so provide powerful indexing and search for your site. It's cool.

## Usage
1. Add the `elasticsearch` folder to your Extensions directory
2. Enable the extension from the Extensions page
3. Check that a directory was created at `/workspace/elasticsearch`
4. Create mapping files in `/workspace/elasticsearch/mappings`
5. Send your mappings to ElasticSearch (using the ElasticSearch > Mappings page)

## Installing ElasticSearch and plugins
You will need to install ElasticSearch (ES) on your server. You'll need the Java installed on the box.

	todo: instructions

ElasticSearch runs from its own webserver on port 9200, therefore a successful installation should yield some Douglas Adams gold at:

	http//yourdomain.com:9200/

### Installing ElasticSearch plugins
There are several ES plugins you will find useful. They are all easy to install and use.

#### TODO: elasticsearch-service
This plugin installs a shortcut to start/stop the ES service on your server. Install the plugin:

	todo

You can `start`, `stop` or `restart` ElasticSearch using the following command from anywhere:

	service elasticsearch restart

#### elasticsearch-head
This provides a UI for browsing your ES cluster, its indexes and content. Use it to test queries and explore new things.

	todo: instructions

Once installed you can view the plugin at:

	http://yourdomain.com:9200/_plugin/head/

#### elasticsearch-mapper-attachments
This allows you to index the contents of binary files such as Word, PDF and [others](todo). Once installed you can use a field type of `attachment` when configuring section mappings (more on this later).

#### todo elasticsearch-http-auth-basic
By default ElasticSearch runs on port 9200 and is therefore open and public. In production environments you should lock down access using Basic HTTP Authentication (username/password, like using .htpasswd). This is provided by the TODO extension. Install by downloading the .jar file to your ES plugins directory

	todo

Then add the plugin configuration to your `elasticsearch.yaml` file:
	
	todo

However leave this disabled for now. Enable it in production and add your username and password to the System > Preferences page.

## Configuring the ElasticSearch extension

Before we go any further, you should know that ElasticSearch is powerful. It uses [Lucene](todo) under the hood, so it supports a ton of things like word stemming, stop words, ngrams, wildcards, accent folding, more like this, synonyms and more. I have written this ElasticSearch extension to provide you with a set of sensible defaults for fulltext search. If you want to change the way this works, then it's simply a case of modifying JSON files. But the idea is that this extension should give you excellent results 90% of the time.

### File structure
On installation the extension will have created a directory in your workspace folder named `elasticsearch` containing the following:

	/workspace
		/elasticsearch
			.htaccess
			index.json
			/mappings

The `.htaccess` file keeps your files private. `index.json` is a JSON document which contains the configuration passed when an ElasticSearch **index** is created. Specifically, this config file specifies two custom **analysers** (`symphony_fulltext` and `symphony_autocomplete`) and two custom filter (`custom_synonyms` and `custom_stop`). More on these later.

(If your permissions prevented these files from being created, create them now by copying the files from the `templates` directory of the extension.)

### Anatomy of ElasticSearch
It's probably best to first describe the nomenclature you need to be familiar with when using ElasticSearch.

ElasticSearch runs as a service on your webserver. If you are running a single server you are running a single ElasticSearch **cluster**. A single cluster can house the search for more than one site, each of which is stored in an **index**. When you use this extension, it will create a single index for your site (e.g. `my-site`). Within an index are **types**. These map nicely onto Symphony sections, e.g. `articles`, `products` or `comments`. ElasticSearch stores **documents** (Symphony entries) which are made up of **fields**.

Fields within a document can be strings, numbers, dates, arrays/collections, or several others. Although ElasticSearch will automatically create a new **type** when you throw a new type of document at it, it is usually best to define the structure of a type first. This is very much like defining a section in Symphony: you define the fields, and properties of these fields. the structure of a type is known as a **mapping**, and is formatted in a JSON file.

The final things to understand are query types, analysers, tokenisers and filters. Stay with me, OK?

A **query type** is how to query ElasticSearch e.g. text, boolean, wildcard, fuzzy. This extension just uses two types: [query_string](todo) and [match_all](todo).

**Analysers** are the logic that is run against both the content you are indexing (an entry) and what you are searching for (a keyword). An analyser comprises a **tokeniser**, which specifies how the tokens (usually words) are broken up (usually based on spaces between words), and **filters**, which work their magic on each word (such as removing stop words, reducing a word to its stem, or replacing with a synonym).

### Modifying the custom analysers

ElasticSearch provides a suite of analysers which all have different combinations of tokenisers and filters. To prevent you from having to read, understand and apply these, this extension provides two custom analysers which are good for most situations. They are called `symphony_fulltext` and `symphony_autocomplete` and are used for fulltext search and search input autocomplete respectively.

They are configured in the `index.json` file in your workspace directory.

#### `symphony_fulltext`
The fulltext analyser uses a suite of filters to strip down text into its most basic form:

* `stop` applies Lucene's default [stop words list](todo)
* `asciifolding` converts accented characters, e.g. `é` becomes `e`
* `snowball` applies word stemming for European languages, e.g. `library` and `libraries` become `librari`
* `lowercase` makes all words case-insensitive
* `custom_synonyms` applies a list of user-defined synonyms
* `custom_stop` applies a list of user-defined stop words

#### `symphony_autocomplete`
The autocomplete analyser is more forgiving than the fulltext analyser and just applies `asciifolding` and `lowercase` filters.

It is important to note that the same analyser must be applied both to the indexed entry _and_ and search keywords. For example if the indexed entry contains the text `School Library`, it would be indexed as `school librari`. If a user searched for `School Library` then it would not be matched! The user's input keywords must also be run through the same analyser, so `school` and `lirari` can be matched.

### Section mappings
This is where the you put your new knowledge to the test, and you map your Symphony sections into ElasticSearch types. This is achieved by creating two files in the `workspace/elasticsearch/mappings` directory for each section you want to index. Let's assume you want to index a section named `Articles` which has four fields:

* Title (input)
* Content (textarea)
* Is Published (checkbox)
* Document (file upload)

Begin by creating a file named `articles.json` in the mappings directory. This file will define the fields that the ElasticSearch document will contain when it indexes an article. You decide that you only want the Title, Content and Document fields indexed for search:

	{
		"articles": {
			"properties": {
				"title": {
					"type" : "multi_field",
					"store": "yes",
					"fields": {
						"title": {"type" : "string"},
						"symphony_fulltext" : {"type" : "string", "analyzer": "symphony_fulltext"},
						"symphony_autocomplete" : {"type" : "string", "analyzer": "symphony_autocomplete"}
					},
					"boost": 3.0
				},
				"content": {
					"type" : "multi_field",
					"store": "yes",
					"fields": {
						"content": {"type" : "string"},
						"symphony_fulltext" : {"type" : "string", "analyzer": "symphony_fulltext"},
						"symphony_autocomplete" : {"type" : "string", "analyzer": "symphony_autocomplete"}
					}
				},
				"document": {
					"type" : "multi_field",
					"store": "yes",
					"fields": {
						"document": {"type" : "attachment"},
						"symphony_fulltext" : {"type" : "attachment", "analyzer": "symphony_fulltext"},
						"symphony_autocomplete" : {"type" : "attachment", "analyzer": "symphony_autocomplete"}
					}
				}
			}
		}
	}

Wow, what is all this about? It's easy. It's an object that matches the handle of your section. Each field that you want indexed is in there. Each field _could_ be defined as a core type such as string, number or date, but we're doing something clever and defining them as a "multi_field" type. This means that for each field we can index them in several (three) different ways:

* `(default)` uses the same name as the field, and just indexes the field as normal, as if we weren't using a multi_type field at all
* `symphony_fulltext` indexes the field again but runs its content through the aforementioned `symphony_fulltext` analyser. Fields that define a `symphony_fulltext` index are searched against for fulltext search when using the search datasource bundled with this extension
* `symphony_autocomplete` indexes the field again but runs its content through the aforementioned `symphony_autocomplete` analyser. Fields that define a `symphony_autocomplete` index are searched against for autocomplete/suggest search when using the autocomplete search datasource bundled with this extension

The last thing of note is the `boost` for the `title` field. This means that text in the title field is ranked three times more important than other fields in the section.

### Mapping Symphony data
Creating the JSON mapping is the first of two steps. The second involves converting Symphony entry data from an array into the JSON that ElasticSearch expects. Again, it's easy. For your Articles section, create a file also in `workspace/elasticsearch/mappings` named `articles.php`.

	<?php
	class elasticsearch_articles {

		public function mapData(Array $data, Entry $entry) {
			$json = array();
			// var_dump($data);

			$json['_boost'] = 1;

			if($data['is-published']['value'] !== 'yes') return;

			$json['title'] = $data['title']['value'];
			$json['content'] = $data['content']['value'];
			$json['document'] = base64_encode(file_get_contents($data['document']['file']));

			return $json;
		}

	}

First of all the class name should match the section handle. Hyphens become underscores. The `mapData` method is provided with the entry's data as an array, and the raw `Entry` object if you need it (you usually won't). This method should return a JSON object containing the data for all fields you specified in the mapping JSON file above.

Adding a `_boost` (note the underscore) to the object will boost this section above others in search results.

If you need to prevent some entries from being indexed, then check them here. In our example an entry has an Is Published checkbox, so we must check that it's value is yes, otherwise the entry should not be indexed. Return `false` from the method to prevent the entry being indexed.

Files can be indexed if you've got the `attachment` plugin installed. Send the file's contents as a base64 encoded string.

### Create the index in ElasticSearch
First things first, we need to create our master index. Navigate to System > Preferences in Symphony and find the ElasticSearch settings.

* `Host` is the full hostname of your ElasticSearch server. If running on the same webserver as Symphony use `http://localhost:9200/`
* `Index Name` is the handle of your index. Maybe `your-site-name`

When you Save Changes, Symphony will connect to ElasticSearch and creates the index. This process sends the `index.json` document, which configures the index as it is created. If you modify the `index.json` file (e.g. you add new stop words or synonyms) then you must recreate the index from scratch. The easiest way to do this is simply to change the `Index Name` and save changes. The old index will be destroyed.

### Submitting the mapping to ElasticSearch
Creating the mapping JSON and PHP files is one step, but the JSON needs to be sent to ElasticSearch for it to build the mapping internally. To do this, navigate to the ElasticSearch > Mappings page in Symphony and you will see a list of sections you have written mappings for.

Select the row and choose `Rebuild Mapping` from the With Selected menu. This will create the mapping type in ElasticSearch and you will be able to index entries!

### Batch indexing entries
From the ElasticSearch > Mappings page, select a row and choose `Reindex Entries` from the With Select menu. This will cycle through all entries in the section and batch-submit them for indexing. This should occur in near real time.

### Congratulations!
You have installed ElasticSearch, configured the Symphony extension, and mapped your Symphony sections to be indexed.

TODO
- fix deleting entry (dynamic section ID)
- add useful stop words
- add synonym examples
- copy across templates to /workspace when installing (create dir structure)