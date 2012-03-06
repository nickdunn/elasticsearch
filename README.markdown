# ElasticSearch

* Version: 0.3.0
* Author: [Nick Dunn](http://nick-dunn.co.uk)
* Build Date: 2012-03-05
* Requirements: Symphony 2.2.5

## Description
The ElasticSearch extension integrates Symphony with [ElasticSearch](http://www.elasticsearch.org/) to provide powerful indexing and search for your site.

## Usage
1. Add the `elasticsearch` folder to your Extensions directory
2. Enable the extension from the Extensions page
3. Check that a directory was created at `/workspace/elasticsearch`
4. Create mapping files in `/workspace/elasticsearch/mappings`
5. Send your mappings to ElasticSearch (using the ElasticSearch > Mappings page)

## Contents

1. [Install ElasticSearch](#es-install)
	* [elasticsearch-servicewrapper](#es-elasticsearch-servicewrapper)
	* [elasticsearch-head](#es-elasticsearch-head)
	* [elasticsearch-mapper-attachments](#es-elasticsearch-mapper-attachments)
	* [elasticsearch-http-basic](#es-elasticsearch-http-basic)
2. [Configure the Symphony extension](#es-configure)
	* [File structure](#es-file-structure)
	* [Anatomy of ElasticSearch](#es-anatomy)
	* [Modifying the custom analysers](#es-analysers)
		* [symphony_fulltext](#es-symphony_fulltext)
		* [symphony_autocomplete](#es-symphony_autocomplete)
	* [Section mappings](#es-section-mappings)
	* [Mapping Symphony data](#es-mapping-symphony-data)
	* [Create the index in ElasticSearch](#es-create-index)
	* [Submitting the mapping to ElasticSearch](#es-submit-mapping)
	* [Batch indexing entries](#es-batch-indexing)
	* [Additional configuration](#es-additional-configuration)
	* [Congratulations](#es-congratulations)
3. [Fulltext search data source](#es-search-datasource)
	* [Example search form](#es-example-search-form)
	* [Example XML response](#es-example-search-response)
4. [Autocomplete](#es-autocomplete)
5. [Logging and analysis](#es-logging)

## <a name="es-install"/> 1. Install ElasticSearch
You will need to install ElasticSearch (ES) on your server:

Linux:

	# replace 0.18.7 with latest stable tag
	cd ~
	wget https://github.com/downloads/elasticsearch/elasticsearch/elasticsearch-0.18.7.tar.gz -O elasticsearch.tar.gz
	tar -xf elasticsearch.tar.gz
	rm elasticsearch.tar.gz
	sudo mv elasticsearch-* elasticsearch
	sudo mv elasticsearch /usr/local/share

Mac OSX:

	brew install elasticsearch

There are several ES plugins you will find useful. They are all easy to install and use.

### <a name="es-elasticsearch-servicewrapper"/> elasticsearch-servicewrapper
This plugin installs a `service` shortcut to start/stop the ES service on your server. Install the plugin (assumes paths above, will be different for an OSX Homebrew install):

	curl -L http://github.com/elasticsearch/elasticsearch-servicewrapper/tarball/master | tar -xz
	mv *servicewrapper*/service /usr/local/share/elasticsearch/bin/
	rm -Rf *servicewrapper*
	sudo /usr/local/share/elasticsearch/bin/service/elasticsearch install
	sudo ln -s `readlink -f /usr/local/share/elasticsearch/bin/service/elasticsearch` /usr/local/bin/rcelasticsearch

You can now `start`, `stop` or `restart` ElasticSearch using the following command from anywhere:

	service elasticsearch start

ElasticSearch runs on port 9200 by default, therefore a successful installation should yield some Douglas Adams gold at:

	http://localhost:9200/

### <a name="es-elasticsearch-head"/> elasticsearch-head
This provides a UI for browsing your ES cluster, its indexes and content. Use it to test queries and explore new things.

	# installs from https://github.com/Aconex/elasticsearch-head
	sudo /usr/local/share/elasticsearch/bin/plugin -install Aconex/elasticsearch-head

Once installed you can view the plugin at:

	http://localhost:9200/_plugin/head/

### <a name="es-elasticsearch-mapper-attachments"/> elasticsearch-mapper-attachments
This allows you to index the contents of binary files such as Word, PDF and [others](http://tika.apache.org/0.9/formats.html). Once installed you can use a field type of `attachment` when configuring section mappings (more on this later).

	# replace 1.2.0 with latest stable tag https://github.com/elasticsearch/elasticsearch-mapper-attachments
	sudo /usr/local/share/elasticsearch/bin/plugin -install elasticsearch/elasticsearch-mapper-attachments/1.2.0

### <a name="es-elasticsearch-http-basic"/> elasticsearch-http-basic
By default ElasticSearch runs on port 9200 and is open and public. If running ElasticSearch on a public webserver, you can lock down access using Basic HTTP authentication. This is provided by the `elasticsearch-http-basic` plugin. Install by downloading the .jar file to your ES plugins directory

	mkdir /usr/local/share/elasticsearch/plugins/http-basic
	cd /usr/local/share/elasticsearch/plugins/http-basic
	wget https://github.com/downloads/Asquera/elasticsearch-http-basic/elasticsearch-http-basic-1.0.3.jar /usr/local/share/elasticsearch/plugins/http-basic

Then add the plugin configuration to your `elasticsearch.yaml` file:
	
	http.basic.enabled: true
	http.basic.user: "my_username"
	http.basic.password: "my_password"

Restart ElasticSearch:
	
	service elasticsearch start

The root of your ElasticSearch server (e.g. http://localhost:9200/) will still return JSON, so you can easily check server status. But other requests will be blocked. Add your username and password to the System > Preferences page in Symphony.


## <a name="es-configure"/> 2. Configure the Symphony extension

Before we go any further, you should know that ElasticSearch is powerful. It uses [Lucene](http://lucene.apache.org/) under the hood, so it supports a ton of things like word stemming, stop words, ngrams, wildcards, accent folding, more like this, synonyms and more. I have written this ElasticSearch extension to provide you with a set of sensible defaults for fulltext search. If you want to change the way this works, then it's simply a case of modifying JSON files. But the idea is that this extension should give you excellent results 90% of the time.

### <a name="es-file-structure"/> File structure
On installation the extension will have created a directory in your workspace folder named `elasticsearch` containing the following:

	/workspace
		/elasticsearch
			.htaccess
			index.json
			/mappings

The `.htaccess` file keeps your files private. `index.json` is a JSON document which contains the configuration passed when an ElasticSearch **index** is created. Specifically, this config file specifies two custom **analysers** (`symphony_fulltext` and `symphony_autocomplete`) and two custom filter (`custom_synonyms` and `custom_stop`). More on these later.

(If your permissions prevented these files from being created, create them now by copying the files from the `templates` directory of the extension.)

### <a name="es-anatomy"/> Anatomy of ElasticSearch
It's probably best to first describe the nomenclature you need to be familiar with when using ElasticSearch.

ElasticSearch runs as a service on your webserver. If you are running a single server you are running a single ElasticSearch **cluster**. A single cluster can house the search for more than one site, each of which is stored in an **index**. When you use this extension, it will create a single index for your site (e.g. `my-site`). Within an index are **types**. These map nicely onto Symphony sections, e.g. `articles`, `products` or `comments`. ElasticSearch stores **documents** (Symphony entries) which are made up of **fields**.

Fields within a document can be strings, numbers, dates, arrays/collections, or several others. Although ElasticSearch will automatically create a new **type** when you throw a new type of document at it, it is usually best to define the structure of a type first. This is very much like defining a section in Symphony: you define the fields, and properties of these fields. the structure of a type is known as a **mapping**, and is formatted in a JSON file.

The final things to understand are query types, analysers, tokenisers and filters. Stay with me, OK?

A **query type** is how to query ElasticSearch e.g. text, boolean, wildcard, fuzzy. This extension just uses two types: [query_string](http://www.elasticsearch.org/guide/reference/query-dsl/query-string-query.html) and [match_all](http://www.elasticsearch.org/guide/reference/query-dsl/match-all-query.html).

**Analysers** are the logic that is run against both the content you are indexing (an entry) and what you are searching for (a keyword). An analyser comprises a **tokeniser**, which specifies how the tokens (usually words) are broken up (usually based on spaces between words), and **filters**, which work their magic on each word (such as removing stop words, reducing a word to its stem, or replacing with a synonym).

### <a name="es-analysers"/> Modifying the custom analysers

ElasticSearch provides a suite of analysers which all have different combinations of tokenisers and filters. To prevent you from having to read, understand and apply these, this extension provides two custom analysers which are good for most situations. They are called `symphony_fulltext` and `symphony_autocomplete` and are used for fulltext search and search input autocomplete respectively.

They are configured in the `index.json` file in your workspace directory.

#### <a name="es-symphony_fulltext"/> symphony_fulltext
The fulltext analyser uses a suite of filters to strip down text into its most basic form:

* `stop` applies Lucene's default [stop words list](https://github.com/apache/lucene-solr/blob/lucene_solr_3_5/lucene/src/java/org/apache/lucene/analysis/StopAnalyzer.java#L49-55)
* `asciifolding` converts accented characters, e.g. `é` becomes `e`
* `snowball` applies word stemming for European languages, e.g. `library` and `libraries` become `librari`
* `lowercase` makes all words case-insensitive
* `custom_synonyms` applies a list of user-defined synonyms
* `custom_stop` applies a list of user-defined stop words

#### <a name="es-symphony_autocomplete"/> symphony_autocomplete
The autocomplete analyser is more forgiving than the fulltext analyser and just applies `asciifolding` and `lowercase` filters.

It is important to note that the same analyser must be applied both to the indexed entry _and_ and search keywords. For example if the indexed entry contains the text `School Library`, it would be indexed as `school librari`. If a user searched for `School Library` then it would not be matched! The user's input keywords must also be run through the same analyser, so `school` and `lirari` can be matched.

### <a name="es-section-mappings"/> Section mappings
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
						"symphony_fulltext" : {"type" : "string", "analyzer": "symphony_fulltext"}
					},
					"symphony_highlight": "yes"
				},
				"document": {
					"type" : "multi_field",
					"store": "yes",
					"fields": {
						"document": {"type" : "attachment"},
						"symphony_fulltext" : {"type" : "attachment", "analyzer": "symphony_fulltext"}
					},
					"symphony_highlight": "yes"
				}
			}
		}
	}

Wow, what is all this about? It's easy. It's an object that matches the handle of your section. Each field that you want indexed is in there. Each field _could_ be defined as a core type such as string, number or date, but we're doing something clever and defining them as a "multi_field" type. This means that for each field we can index them in several (three) different ways:

* `(default)` uses the same name as the field, and just indexes the field as normal, as if we weren't using a multi_type field at all
* `symphony_fulltext` indexes the field again but runs its content through the aforementioned `symphony_fulltext` analyser. Fields that define a `symphony_fulltext` index are searched against for fulltext search when using the search datasource bundled with this extension
* `symphony_autocomplete` indexes the field again but runs its content through the aforementioned `symphony_autocomplete` analyser. Fields that define a `symphony_autocomplete` index are searched against for autocomplete/suggest search when using the autocomplete search datasource bundled with this extension

Adding a `boost` property for `title` ranks this field three times more important than other fields in the section. Adding a `symphony_highlight` property for `content` and `document` configures ElasticSearch to return excerpts from these fields with the search terms highlighted. See [Example XML response](#) for more.

(Note: `symphony_highlight` is a custom property you won't find in the ElasticSearch docs — it is just used by this extension).

### <a name="es-mapping-symphony-data"/> Mapping Symphony data
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

### <a name="es-create-index"/> Create the index in ElasticSearch
First things first, we need to create our master index. Navigate to System > Preferences in Symphony and find the ElasticSearch settings.

* `Host` is the full hostname of your ElasticSearch server. If running on the same webserver as Symphony use `http://localhost:9200/`
* `Index Name` is the handle of your index. Maybe `your-site-name`

When you Save Changes, Symphony will connect to ElasticSearch and creates the index. This process sends the `index.json` document, which configures the index as it is created. If you modify the `index.json` file (e.g. you add new stop words or synonyms) then you must recreate the index from scratch. The easiest way to do this is simply to change the `Index Name` and save changes. The old index will be destroyed.

### <a name="es-submit-mapping"/> Submitting the mapping to ElasticSearch
Creating the mapping JSON and PHP files is one step, but the JSON needs to be sent to ElasticSearch for it to build the mapping internally. To do this, navigate to the ElasticSearch > Mappings page in Symphony and you will see a list of sections you have written mappings for.

Select the row and choose `Rebuild Mapping` from the With Selected menu. This will create the mapping type in ElasticSearch and you will be able to index entries!

### <a name="es-batch-indexing"/> Batch indexing entries
From the ElasticSearch > Mappings page, select a row and choose `Reindex Entries` from the With Select menu. This will cycle through all entries in the section and batch-submit them for indexing. This should occur in near real time.

### <a name="es-additional-configuration"/> Additional configuration
All configuration options are stored in the Symphony config file.

* `host` root URL of your ElasticSearch server (e.g. `http://localhost:9200/`)
* `index-name` index name (e.g. `my-site`)
* `reindex-batch-size` (default `20`) number of simultaneously entries to reindex when manually reindexing a section
* `reindex-batch-delay` (default `0`) number of seconds between each batch (reduce if you find this activity hogs server resources, allows the server to recover between each batch!)
* `per-page` (default `20`) default number of entries per page of search results
* `sort` (default `_score`) default sort field (use ElasticSearch fields like `_score` or `_id` for best results)
* `direction` (default `desc`) default sort direction
* `highlight-fragment-size` (default `200`) maximum number of characters of each excerpt highlight
* `highlight-per-field` (default `1`) maximum number of highlights returned per field
* `build-entry-xml` (default `no`) whether to build full entry XML (all fields) in search results
* `default-sections` (default ``) default list of section handles to search in (comma-delimited)
* `default-language` (default ``) default list of languages to search in (comma-delimited)
* `logging` (default `yes`) whether to log each keyword search

### <a name="es-congratulations"/> Congratulations!
You have installed ElasticSearch, configured the Symphony extension, and mapped your Symphony sections to be indexed.


## <a name="es-search-datasource"/> 3. Fulltext search data source
Fulltext search across your indexed actions can be achieved using the custom ElasticSearch data source included with this extension. Attach this data source to you search results page and invoke it using the following GET parameters:

* `keywords` the string to search on e.g. `foo bar`
* `page` the results page number
* `sort` (default `_score`) the field to sort results by
* `direction` (default `desc`) either `asc` or `desc`
* `per-page` (default `20`) number of results per page
* `sections` a comma-delimited list of section handles to search within (only indexed sections will work) e.g. `articles,comments`
* `language` an optional string referring to the language code of your indexed fields (see [Multilingual search](#es-multilingual-search))

The datasource executes a [query_string query](#) against any multi_type field with a field name of `symphony_fulltext`.

### <a name="es-example-search-form"/> Example search form

Your search form might look like this:

	<form action="/search/" method="get">
		<label>Search <input type="text" name="keywords" /></label>
		<input type="hidden" name="per-page" value="10" />
		<input type="hidden" name="sections" value="articles,comments,categories" />
	</form>

Note that all of these variables (except for `keywords`) have defaults in `config.php`. Change them in your config file and omit them from the URL.

### <a name="es-example-search-response"/> Example XML response

The XML returned from this data source looks like this:

	<elasticsearch took="54ms" max-score="0.7293">
		<keywords>foo bar</keywords>
		<pagination total-entries="5" total-pages="1" entries-per-page="20" current-page="1" />
		<facets>
			<facet handle="filtered-sections">
				<term handle="articles" entries="3" active="yes">Articles</term>
				<term handle="comments" entries="2" active="yes">Comments</term>
			</facet>
			<facet handle="all-sections">
				<term handle="articles" entries="100" active="yes">Articles</term>
				<term handle="comments" entries="391" active="yes">Comments</term>
			</facet>
		</facets>
		<entries>
			<entry id="2" section="articles" score="0.7293">
				<highlight field="title">My favourite words are <strong class="highlight">foo</strong> and <strong class="highlight">bar</strong>, but don't tell fred!</highlight>
			</entry>
			<entry id="4" section="comments" score="0.6213">...</entry>
			<entry id="3" section="articles" score="0.5004">...</entry>
			<entry id="1" section="articles" score="0.4277">...</entry>
			<entry id="5" section="comments" score="0.2651">...</entry>
		</entries>
	</elasticsearch>

The query returns two [facets](http://www.elasticsearch.org/guide/reference/api/search/facets/) which are used as a breakdown of entries across sections. `filtered-sections` lists the sections for which entries were found, and how many. `all-sections` lists all sections and how many entries, regardless of the search query. The `@active` attribute is `yes` if the search is running on that section:

* if `?sections=articles,comments` is passed on the querystring then these sections will be used
* if not, the `default-sections` list from Symphony's `manifest/config.php` file will be used
* if not, all indexed sections are used

However this output is not sufficient to build a search results page (SERP) — you need the entries themselves. You can achieve this in one of two ways:

1. use the `$ds-elasticsearch` output parameter from this data source to chain additional data sources to return the full entry XML
2. set `build-entry-xml` to `yes` in Symphony's `manifest/config.php`, and the entry's fields will be appended to the XML


## <a name="es-autocomplete"/> Autocomplete
There is a second `ElasticSearch: Suggest` data source provided by this extension which, given a partial search term, will perform a wildcard search and return suggested phrases. This can be used for a basic autocomplete search box.

Create a new page, give it a page type of `XML`, attach the suggest data source, and this XSLT:

	<?xml version="1.0" encoding="UTF-8"?>
	<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform">

		<xsl:output method="xml" omit-xml-declaration="yes" encoding="UTF-8" indent="yes" />

		<xsl:template match="/">
			<xsl:copy-of select="data/elasticsearch-suggest/words" />
		</xsl:template>

	</xsl:stylesheet>

This query will be run against any multi_type field with a name of `symphony_autocomplete`, so only specify this name for fields that make sense for autocomplete. Choose fields like post titles, product SKUs and people's names.

Pass the following querystring parameters:

* `keywords` the string to search on e.g. `foo bar`
* `sort` (default `_score`) the field to sort results by
* `sections` a comma-delimited list of section handles to search within (only indexed sections will work) e.g. `articles,comments`

The XML result looks like:

	<words>
		<word>
			<raw>The Story of Foo and his Bar!</raw>
			<highlighted>The Story Of &lt;strong&gt;Foo&lt;/strong&gt; and his &lt;strong&gt;Bar&lt;/strong&gt;!</highlighted>
		</word>
		...
	</words>

The `raw` element contains plain text while `highlighted` contains the string with matching full words highlighed. The result is entity-encoded to make JavaScript processing easier (treat it as plain text).

## <a name="es-multilingual-search" /> Multilingual Search
While ElasticSearch does not support multilingual content out of the box, it is still still possible to index and search your multilingual entries by adhering to a simple naming convention that this extension uses.

Let's say you have an Articles section with two multilingual fields: Title and Content. When you create the section mapping, you can map each of these fields for each language. For example mapping the two fields for English and German:

	{
		"articles": {
			"properties": {
				"title_en": {
					"type" : "multi_field",
					"store": "yes",
					"fields": {
						"title_en": {"type" : "string"},
						...
					}
				},
				"title_de": {
					"type" : "multi_field",
					"store": "yes",
					"fields": {
						"title_de": {"type" : "string"},
						...
					}
				},
				"content_en": {
					"type" : "multi_field",
					"store": "yes",
					"fields": {
						"title_en": {"type" : "string"},
						...
					}
				},
				"content_de": {
					"type" : "multi_field",
					"store": "yes",
					"fields": {
						"title_de": {"type" : "string"},
						...
					}
				}
			}
		}
	}

And the PHP mapper (note the structure for getting the per-language data might vary depending on which multilingual extension you are using in Symphony):

	<?php
	class elasticsearch_articles {
		public function mapData(Array $data, Entry $entry) {
			$json = array();
			// title
			$json['title_en'] = $data['title']['value']['en'];
			$json['title_de'] = $data['title']['value']['de'];
			// content
			$json['content_en'] = $data['content']['value']['en'];
			$json['content_de'] = $data['content']['value']['de'];
			return $json;
		}
	}

Two Symphony fields, mapped to four ElasticSearch fields.

To search a specific language only, you can pass `language` URL parameter to your search page. For example:

	http://localhost/search/?sections=articles&keywords=foo+bar&language=en

Multiple languages can be searched at once:

	http://localhost/search/?sections=articles&keywords=foo+bar&language=en,de

Omit the `language` parameter to search all fields. If `language` is omitted you can specify a default for the `default-language` property the Symphony config file. The same convention also applies to the autocomplete data source.

## <a name="es-logging"/> Logging and analysis

If you have never looked over a search log, then shame on you. Do yourself a favour and read Lou Rosenfold's [Search Analytics For Your Site](http://rosenfeldmedia.com/books/searchanalytics/) to be instantly convinced that optimising search will benefit you and your users.

You can [configure Google Analytics to track searches on your site](http://support.google.com/analytics/bin/answer.py?hl=en&answer=1012264). It will show you which terms were searched for, and which pages people started searching from (which usually means that page should contain information regarding their search term!). However Google Analytics isn't a dedicate search term analytics tool and doesn't give you the granular breakdown that analytics nerds so desperately desire. 

To this end, this extension logs every search query it makes (disable logging in the config) for you to pore over in your spare time. Logs are broken down by:

* **Session Logs** shows each individual user session, use this to spot behaviours such as [pogo-sticking](http://wlion.com/blog/2006/06/19/who-really-cares-about-pogo-sticking/), the difference between mobile and desktop use, and how users correct search terms (which can suggest synonyms to add to the index)
* **Query Logs** shows most popular search terms, so you can see which terms are used the most, the least, whether they return many hits, and whether people are prepared to sift through many pages


## Todo
- support for multilang using naming conventions e.g. *_en.symphony_fulltext