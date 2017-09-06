<?php

/*
 * This file is part of the Pho package.
 *
 * (c) Emre Sokullu <emre@phonetworks.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Pho\Kernel\Services\Index\Adapters;

use Pho\Kernel\Kernel;
use Pho\Kernel\Services\ServiceInterface;
use Elasticsearch\ClientBuilder;

/**
 * File based logging. The log files specified at the kernel
 * constructor level or via the *configure* function of the kernel.
 *
 * @author Emre Sokullu
 */
class Elasticsearch implements IndexInterface, ServiceInterface
{
     /**
     * Pho-kernel 
     * @var \Pimple
     */
    protected $kernel;

    /**
     * Elasticsearch php sdk client connect 
     * @var \Elasticsearch\Client
     */
    protected $client;

    /**
     * default index name for pho-indexing
     * @var string
     */
    private $dbname    = 'phonetworks';


    /**
     * Setup function.
     * Init elasticsearch connection. Run indexing on runned events
     * @param Kernel $kernel Kernel of pho
     * @param array  $params Sended params to the index.
     */
    public function __construct(Kernel $kernel, array $params = [])
    {
        $this->kernel = $kernel;
        $this->dbname = getenv('INDEX_DB')?: $this->dbname;

        $host         = [$params['host'] ?: getenv('INDEX_URL') ?: 'http://127.0.0.1:9200/'];
        $client = new \Elasticsearch\ClientBuilder($host);
        $this->client = $client->build();

        $indexParams['index'] = $this->dbname;

        if ( ! $this->client->indices()->exists($indexParams)) {
            $this->client->indices()->create($indexParams);
            $this->kernel->logger()->info("Created Elasticsearch index with name: %s", $this->dbname);
        }

        $kernel->on('kernel.booted_up', array($this, 'kernelBooted'));
    }


    /**
     * Indexes an entity.
     *
     * @param EntityInterface $entity An entity object; node or edge.
     * @param bool $new Whether the object has just been initialized, hence never been indexed before.
     *
     * @return void
     */
    public function index(EntityInterface $entity, bool $new = false): void
    {
        $classes = [get_class($entity) => get_class($entity)] + class_parents($entity);
        if ($new) {
            $this->addToIndex($entity->id()->toString(), $entity->attributes()->toArray(), $classes);
        } else {
            $this->editInIndex($entity->id()->toString(), $entity->attributes()->toArray(), $classes);
        }
    }

    /**
     * Searches through the index with given key and its value.
     *
     * @param string $value Value to search
     * @param string $key The key to search for. Optional.
     * @param array $classes The object classes to search for. Optional.
     *
     * @return array
     */
    public function search(string $value, string $key = null, array $classes = array()): array
    {
        return $this->searchInIndex($value, $key, $classes);
    }

    /**
     * Searches through the index with given key and its value.
     *
     * Returns the entity IDs as string
     *
     * @param string $value Value to search
     * @param string $key The key to search for. Optional.
     * @param array $classes The object classes to search for. Optional.
     *
     * @return array Entity IDs (in string format) in question
     */
    public function searchFlat(string $value, string $key = "", array $classes = array()): array
    {
        return [];
    }

    public function kernelBooted()
    {
        var_dump('Kernel booted');
        $this->kernel->graph()->on('node.added', array($this, 'index'));
    }

    /**
     * Bulk insert params of entity to the index
     * @param string $id     uuid of entity
     * @param array  $params array of params with key => value structure (toArray() method)
     * @param array  $classes classes of the current entity
     */
    private function addToIndex(string $id, array $params, array $classes = []): void
    {
        $body = ['attr' => [], 'classes' => $classes, 'id' => $id];
        foreach ($params as $key => $value) {
            $body['attr'][] = ['k' => $key, 'v' => (string)$value];
        }
        $class = $this->getTypeFromClass($classes);

        $query = $this->createQuery($class, $id, $body);
        $this->client->index($query);
    }

    /**
     * Updating existing entity attributes to the Indexing DB
     * @param  string $id      uuid/id of entity
     * @param  array  $results array of attributes with key => value structure (toArray() method)
     * @param array  $classes classes of the current entity
     */
    private function editInIndex(string $id, array $params, array $classes = []): void
    {
        //If node not founded in index - add it.
        $founded = $this->searchById($id);
        if ( ! empty($founded)) {
            $type = $this->getTypeFromClass($founded['classes']);
            $this->removeById($type, $id);
        }

        //Update document if node are exists
        $body = ['attr' => [], 'classes' => $classes];
        foreach ($params as $key => $value) {
            $body['attr'][] = ['k' => $key, 'v' => (string)$value];
        }

        $params = $this->createQuery($this->getTypeFromClass($classes), $id, $body);
        $this->client->index($params);
    }

    /**
     * Search in indexing DB all attributes of entity by its ID
     * @param  string $id uuid string
     * @return array     array with keys id, key, value
     */
    private function searchById(string $id): array
    {
        try {
            $query = ['query' => ['match' => ['id' => $id]]];
            $params = $this->createQuery(null, null, $query);
            
            $results = $this->client->search($params);
        } catch (Elasticsearch\Common\Exceptions\TransportException $e) {
            return false;
        } catch (Elasticsearch\Common\Exceptions\Missing404Exception $e) {
            return false;
        }
        $founded = $this->remapReturn($results);
        if (isset($founded[0])) {
            return $founded[0];
        } else {
            return $founded;
        }
    }

    /**
     * Search in indexing DB all attributes of entity by its ID
     * @param  string $id uuid string
     * @return array     array with keys id, key, value
     */
    private function removeById(string $type, string $id): array
    {
        $params = $this->createQuery($type, $id, false);
        return $this->client->delete($params);
    }

    /**
     * Search by some params
     * @param  string $id uuid string
     * @return array     array with keys id, key, value
     */
    private function searchInIndex(string $value, string $key = null, array $classes = array()): array
    {
        $query                             = ['query' => ['bool' => ['must' => []]]];
        $query['query']['bool']['must'][]['match']['attr.v'] = $value;
        if ( ! is_null($key)) {
            $query['query']['bool']['must'][]['match']['attr.k'] = $key;
        }
        
        $params = $this->createQuery(implode(',', (array)$classes), null, $query);
        $results = $this->client->search($params);
        return $this->getIdsList($this->remapReturn($results));
    }

    public function getTypeFromClass($classes) {
        $type = 'entity';
        $class = false;

        if (is_array($classes)) {
            $class = array_shift($classes);
        }

        if (is_string($classes)) {
            $class = $classes;
        }

        if ($class){
            $type = substr($class, strrpos($class, '\\') + 1);
        }


        return $type;
    }

    private function remapReturn(array $results)
    {
        $return = array();
        if (isset($results['hits']) && isset($results['hits']['hits'])) {
            foreach ($results['hits']['hits'] as $founded) {
                $a = [
                    'id'         => $founded['_source']['id'],
                    'attributes' => [],
                    'classes'    => $founded['_source']['classes'],
                ];
                foreach ($founded['_source']['attr'] as $attribute) {
                    $a['attributes'][$attribute['k']] = $attribute['v']; 
                }
                array_push($return, $a);
            }
        } else if (isset($results['_id']) && isset($results['_source'])) {
            $return['id'] = $results['_source']['id'];
            $return['classes'] = $results['_source']['classes'];
            $return['type'] = $results['_type'];
            foreach ($results['_source']['attr'] as $attribute) {
                $return['attributes'][$attribute['k']] = $attribute['v']; 
            }   
        }
        return $return;
    }

    private function getIdsList($founded): array
    {
        $ids = [];
        foreach ($founded as $entitys) {
            $ids[] = $entitys['id'];
        }

        return $ids;
    }

    private function createQuery(string $type = null, string $id = null, $where = []): array
    {
        $query = ['index' => $this->dbname];
        if ($type) {
            $query['type'] = $type;
        }

        if ($where !== false)
        {
            $query['body'] = $where;
        }

        if (!is_null($id)) {
            $query['id'] = $id;
        }

        return $query;
    }

}
