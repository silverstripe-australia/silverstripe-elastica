<?php

namespace SilverStripe\Elastica;

use Elastica\Client;
use Elastica\Query;
use Elastica\Query\MultiMatch;

/**
 * A service used to interact with elastic search.
 */
class ElasticaService {

	/**
	 * @var \Elastica\Document[]
	 */
	protected $buffer = array();

	/**
	 * @var bool controls whether indexing operations are buffered or not
	 */
	protected $buffered = false;

	private $client;
	private $index;

	/**
	 * @param \Elastica\Client $client
	 * @param string $index
	 */
	public function __construct(Client $client, $index) {
		$this->client = $client;
		$this->index = $index;
	}

	/**
	 * @return \Elastica\Client
	 */
	public function getClient() {
		return $this->client;
	}

	/**
	 * @return \Elastica\Index
	 */
	public function getIndex() {
		return $this->getClient()->getIndex($this->index);
	}

	/**
	 * Performs a search query and returns a result list.
	 *
	 * @param \Elastica\Query|string|array $query
	 * @param array $fields An optional list of fields to search
	 * @return ResultList
	 */
	public function search($query, $fields = array ()) {
		if(!empty($fields)) {
			$q = new MultiMatch();
			$q->setQuery($query);
			$q->setFields($fields);
		}
		else {
			$q = Query::create($query);
		}

		return new ResultList($this->getIndex(), $q);
	}

	/**
	 * Either creates or updates a record in the index.
	 *
	 * @param Searchable $record
	 */
	public function index($record) {
		$document = $record->getElasticaDocument();
		$type = $record->getElasticaType();

		if ($this->buffered) {
			if (array_key_exists($type, $this->buffer)) {
				$this->buffer[$type][] = $document;
			} else {
				$this->buffer[$type] = array($document);
			}
		} else {
			$index = $this->getIndex();

			$index->getType($type)->addDocument($document);
			$index->refresh();
		}
	}

	/**
	 * Begins a bulk indexing operation where documents are buffered rather than
	 * indexed immediately.
	 */
	public function startBulkIndex() {
		$this->buffered = true;
	}

	/**
	 * Ends the current bulk index operation and indexes the buffered documents.
	 */
	public function endBulkIndex() {
		$index = $this->getIndex();

		foreach ($this->buffer as $type => $documents) {
			$index->getType($type)->addDocuments($documents);
			$index->refresh();
		}

		$this->buffered = false;
		$this->buffer = array();
	}

	/**
	 * Deletes a record from the index.
	 *
	 * @param Searchable $record
	 */
	public function remove($record) {
		$index = $this->getIndex();
		$type = $index->getType($record->getElasticaType());

		$type->deleteDocument($record->getElasticaDocument());
	}

	/**
	 * Creates the index and the type mappings.
	 */
	public function define() {
		$index = $this->getIndex();

		if (!$index->exists()) {
			$index->create();
		}

		foreach ($this->getIndexedClasses() as $class) {
			/** @var $sng Searchable */
			$sng = singleton($class);

			$mapping = $sng->getElasticaMapping();
			$mapping->setType($index->getType($sng->getElasticaType()));
			$mapping->send();
		}
	}

	/**
	 * Re-indexes each record in the index.
	 */
	public function refresh() {
		$index = $this->getIndex();
		$this->startBulkIndex();

		foreach ($this->getIndexedClasses() as $class) {
			foreach ($class::get() as $record) {
				$this->index($record);
			}
		}

		$this->endBulkIndex();
	}

	/**
	 * Gets the classes which are indexed (i.e. have the extension applied).
	 *
	 * @return array
	 */
	public function getIndexedClasses() {
		$classes = array();

		foreach (\ClassInfo::subclassesFor('DataObject') as $candidate) {
			if (singleton($candidate)->hasExtension('SilverStripe\\Elastica\\Searchable')) {
				$classes[] = $candidate;
			}
		}

		return $classes;
	}

}
