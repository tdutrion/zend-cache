<?php
/**
 * Zend Framework
 *
 * LICENSE
 *
 * This source file is subject to the new BSD license that is bundled
 * with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://framework.zend.com/license/new-bsd
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@zend.com so we can send you a copy immediately.
 *
 * @category   Zend
 * @package    Zend_Cache
 * @subpackage Storage
 * @copyright  Copyright (c) 2005-2011 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 */

namespace Zend\Cache\Storage\Adapter;

use ArrayObject,
    Memcached as MemcachedResource,
    stdClass,
    Traversable,
    Zend\Cache\Exception,
    Zend\Cache\Storage\Capabilities;

/**
 * @package    Zend_Cache
 * @subpackage Zend_Cache_Storage
 * @subpackage Storage
 * @copyright  Copyright (c) 2005-2011 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 * @todo       Implement the find() method
 */
class Memcached extends AbstractAdapter
{
    /**
     * Memcached instance
     *
     * @var MemcachedResource
     */
    protected $memcached;

    /**
     * Constructor
     *
     * @param  null|array|Traversable|MemcachedOptions $options
     * @throws Exception
     * @return void
     */
    public function __construct($options = null)
    {
        if (!extension_loaded('memcached')) {
            throw new Exception\ExtensionNotLoadedException("Memcached extension is not loaded");
        }

        $this->memcached= new MemcachedResource();

        parent::__construct($options);

        $options = $this->getOptions();
        $this->memcached->addServer($options->getServer(), $options->getPort());

    }

    /* options */

    /**
     * Set options.
     *
     * @param  array|Traversable|MemcachedOptions $options
     * @return Memcached
     * @see    getOptions()
     */
    public function setOptions($options)
    {
        if (!is_array($options)
            && !$options instanceof Traversable
            && !$options instanceof MemcachedOptions
        ) {
            throw new Exception\InvalidArgumentException(sprintf(
                '%s expects an array, a Traversable object, or a MemcachedOptions object; '
                . 'received "%s"',
                __METHOD__,
                (is_object($options) ? get_class($options) : gettype($options))
            ));
        }

        if (!$options instanceof MemcachedOptions) {
            $options = new MemcachedOptions($options);
        }

        $this->options = $options;

        // Set memcached options, using options map to map to Memcached constants
        $map = $options->getOptionsMap();
        foreach ($options->toArray() as $key => $value) {
            if (!array_key_exists($key, $map)) {
                // skip keys for which there are not equivalent options
                continue;
            }
            $this->memcached->setOption($map[$key], $value);
        }

        return $this;
    }

    /**
     * Get options.
     *
     * @return MemcachedOptions
     * @see setOptions()
     */
    public function getOptions()
    {
        if (!$this->options) {
            $this->setOptions(new MemcachedOptions());
        }
        return $this->options;
    }

    /* reading */

    /**
     * Get an item.
     *
     * Options:
     *  - namespace <string> optional
     *    - The namespace to use (Default: namespace of object)
     *  - ignore_missing_items <boolean> optional
     *    - Throw exception on missing item or return false
     *
     * @param  string $key
     * @param  array $options
     * @return mixed Value on success and false on failure
     * @throws Exception
     *
     * @triggers getItem.pre(PreEvent)
     * @triggers getItem.post(PostEvent)
     * @triggers getItem.exception(ExceptionEvent)
     */
    public function getItem($key, array $options = array())
    {
        $baseOptions = $this->getOptions();
        if (!$baseOptions->getReadable()) {
            return false;
        }

        $this->normalizeOptions($options);
        $this->normalizeKey($key);
        $args = new ArrayObject(array(
            'key'     => & $key,
            'options' => & $options,
        ));

        try {
            $eventRs = $this->triggerPre(__FUNCTION__, $args);
            if ($eventRs->stopped()) {
                return $eventRs->last();
            }

            $internalKey = $options['namespace'] . $baseOptions->getNamespaceSeparator() . $key;
            $result      = $this->memcached->get($internalKey);
            if ($result===false) {
                if (!$options['ignore_missing_items']) {
                    throw new Exception\ItemNotFoundException("Key '{$internalKey}' not found");
                }
            } else {
                if (array_key_exists('token', $options)) {
                    $options['token'] = $result;
                }
            }

            return $this->triggerPost(__FUNCTION__, $args, $result);
        } catch (\Exception $e) {
            return $this->triggerException(__FUNCTION__, $args, $e);
        }
    }

    /**
     * Get multiple items.
     *
     * Options:
     *  - namespace <string> optional
     *    - The namespace to use (Default: namespace of object)
     *
     * @param  array $keys
     * @param  array $options
     * @return array Assoziative array of existing keys and values or false on failure
     * @throws Exception
     *
     * @triggers getItems.pre(PreEvent)
     * @triggers getItems.post(PostEvent)
     * @triggers getItems.exception(ExceptionEvent)
     */
    public function getItems(array $keys, array $options = array())
    {
        $baseOptions = $this->getOptions();
        if (!$baseOptions->getReadable()) {
            return array();
        }

        $this->normalizeOptions($options);
        $args = new ArrayObject(array(
            'keys'    => & $keys,
            'options' => & $options,
        ));

        try {
            $eventRs = $this->triggerPre(__FUNCTION__, $args);
            if ($eventRs->stopped()) {
                return $eventRs->last();
            }

            $namespaceSep = $baseOptions->getNamespaceSeparator();
            $internalKeys = array();
            foreach ($keys as $key) {
                $internalKeys[] = $options['namespace'] . $namespaceSep . $key;
            }

            $fetch = $this->memcached->getMulti($internalKeys);

            if ($fetch===false) {
                throw new Exception\ItemNotFoundException('Memcached::getMulti(<array>) failed');
            }

            // remove namespace prefix
            $prefixL = strlen($options['namespace'] . $namespaceSep);
            $result  = array();
            foreach ($fetch as $internalKey => &$value) {
                $result[ substr($internalKey, $prefixL) ] = $value;
            }

            return $this->triggerPost(__FUNCTION__, $args, $result);
        } catch (\Exception $e) {
            return $this->triggerException(__FUNCTION__, $args, $e);
        }
    }

    /**
     * Get metadata of an item.
     *
     * Options:
     *  - namespace <string> optional
     *    - The namespace to use (Default: namespace of object)
     *  - ignore_missing_items <boolean> optional
     *    - Throw exception on missing item or return false
     *
     * @param  string $key
     * @param  array $options
     * @return array|boolean Metadata or false on failure
     * @throws Exception
     *
     * @triggers getMetadata.pre(PreEvent)
     * @triggers getMetadata.post(PostEvent)
     * @triggers getMetadata.exception(ExceptionEvent)
     */
    public function getMetadata($key, array $options = array())
    {
        $baseOptions = $this->getOptions();
        if (!$baseOptions->getReadable()) {
            return false;
        }

        $this->normalizeOptions($options);
        $this->normalizeKey($key);
        $args = new ArrayObject(array(
            'key'     => & $key,
            'options' => & $options,
        ));

        try {
            $eventRs = $this->triggerPre(__FUNCTION__, $args);
            if ($eventRs->stopped()) {
                return $eventRs->last();
            }

            $internalKey = $options['namespace'] . $baseOptions->getNamespaceSeparator() . $key;
            $result      = $this->memcached->get($internalKey);
            if ($result===false) {
                if (!$options['ignore_missing_items']) {
                    throw new Exception\ItemNotFoundException("Key '{$internalKey}' not found");
                }
            } else {
                $result = array();
            }

            return $this->triggerPost(__FUNCTION__, $args, $result);
        } catch (\Exception $e) {
            return $this->triggerException(__FUNCTION__, $args, $e);
        }
    }

    /* writing */

    /**
     * Store an item.
     *
     * Options:
     *  - ttl <float> optional
     *    - The time-to-life (Default: ttl of object)
     *  - namespace <string> optional
     *    - The namespace to use (Default: namespace of object)
     *
     * @param  string $key
     * @param  mixed $value
     * @param  array $options
     * @return boolean
     * @throws Exception
     *
     * @triggers setItem.pre(PreEvent)
     * @triggers setItem.post(PostEvent)
     * @triggers setItem.exception(ExceptionEvent)
     */
    public function setItem($key, $value, array $options = array())
    {
        $baseOptions = $this->getOptions();
        if (!$baseOptions->getWritable()) {
            return false;
        }

        $this->normalizeOptions($options);
        $this->normalizeKey($key);
        $args = new ArrayObject(array(
            'key'     => & $key,
            'value'   => & $value,
            'options' => & $options,
        ));

        try {
            $eventRs = $this->triggerPre(__FUNCTION__, $args);
            if ($eventRs->stopped()) {
                return $eventRs->last();
            }

            $internalKey = $options['namespace'] . $baseOptions->getNamespaceSeparator() . $key;
            if (!$this->memcached->set($internalKey, $value, $options['ttl'])) {
                $type = is_object($value) ? get_class($value) : gettype($value);
                throw new Exception\RuntimeException(
                    "Memcached::set('{$internalKey}', <{$type}>, {$options['ttl']}) failed"
                );
            }

            $result = true;
            return $this->triggerPost(__FUNCTION__, $args, $result);
        } catch (\Exception $e) {
            return $this->triggerException(__FUNCTION__, $args, $e);
        }
    }

    /**
     * Store multiple items.
     *
     * Options:
     *  - ttl <float> optional
     *    - The time-to-life (Default: ttl of object)
     *  - namespace <string> optional
     *    - The namespace to use (Default: namespace of object)
     *
     * @param  array $keyValuePairs
     * @param  array $options
     * @return boolean
     * @throws Exception
     *
     * @triggers setItems.pre(PreEvent)
     * @triggers setItems.post(PostEvent)
     * @triggers setItems.exception(ExceptionEvent)
     */
    public function setItems(array $keyValuePairs, array $options = array())
    {
        $baseOptions = $this->getOptions();
        if (!$baseOptions->getWritable()) {
            return false;
        }

        $this->normalizeOptions($options);
        $args = new ArrayObject(array(
            'keyValuePairs' => & $keyValuePairs,
            'options'       => & $options,
        ));

        try {
            $eventRs = $this->triggerPre(__FUNCTION__, $args);
            if ($eventRs->stopped()) {
                return $eventRs->last();
            }

            $internalKeyValuePairs = array();
            $prefix                = $options['namespace'] . $baseOptions->getNamespaceSeparator();
            foreach ($keyValuePairs as $key => &$value) {
                $internalKey = $prefix . $key;
                $internalKeyValuePairs[$internalKey] = &$value;
            }

            $errKeys = $this->memcached->setMulti($internalKeyValuePairs, $options['ttl']);
            if ($errKeys==false) {
                throw new Exception\RuntimeException("Memcached::setMulti(<array>, {$options['ttl']}) failed");
            }

            $result = true;
            return $this->triggerPost(__FUNCTION__, $args, $result);
        } catch (\Exception $e) {
            return $this->triggerException(__FUNCTION__, $args, $e);
        }
    }

    /**
     * Add an item.
     *
     * Options:
     *  - ttl <float> optional
     *    - The time-to-life (Default: ttl of object)
     *  - namespace <string> optional
     *    - The namespace to use (Default: namespace of object)
     *
     * @param  string $key
     * @param  mixed  $value
     * @param  array  $options
     * @return boolean
     * @throws Exception
     *
     * @triggers addItem.pre(PreEvent)
     * @triggers addItem.post(PostEvent)
     * @triggers addItem.exception(ExceptionEvent)
     */
    public function addItem($key, $value, array $options = array())
    {
        $baseOptions = $this->getOptions();
        if (!$baseOptions->getWritable()) {
            return false;
        }

        $this->normalizeOptions($options);
        $this->normalizeKey($key);
        $args = new ArrayObject(array(
            'key'     => & $key,
            'value'   => & $value,
            'options' => & $options,
        ));

        try {
            $eventRs = $this->triggerPre(__FUNCTION__, $args);
            if ($eventRs->stopped()) {
                return $eventRs->last();
            }

            $internalKey = $options['namespace'] . $baseOptions->getNamespaceSeparator() . $key;
            if (!$this->memcached->add($internalKey, $value, $options['ttl'])) {
                if ($this->memcached->get($internalKey)!==false) {
                    throw new Exception\RuntimeException("Key '{$internalKey}' already exists");
                }

                $type = is_object($value) ? get_class($value) : gettype($value);
                throw new Exception\RuntimeException(
                    "Memcached::add('{$internalKey}', <{$type}>, {$options['ttl']}) failed"
                );
            }

            $result = true;
            return $this->triggerPost(__FUNCTION__, $args, $result);
        } catch (\Exception $e) {
            return $this->triggerException(__FUNCTION__, $args, $e);
        }
    }

    /**
     * Replace an item.
     *
     * Options:
     *  - ttl <float> optional
     *    - The time-to-life (Default: ttl of object)
     *  - namespace <string> optional
     *    - The namespace to use (Default: namespace of object)
     *
     * @param  string $key
     * @param  mixed  $value
     * @param  array  $options
     * @return boolean
     * @throws Exception
     *
     * @triggers replaceItem.pre(PreEvent)
     * @triggers replaceItem.post(PostEvent)
     * @triggers replaceItem.exception(ExceptionEvent)
     */
    public function replaceItem($key, $value, array $options = array())
    {
        $baseOptions = $this->getOptions();
        if (!$baseOptions->getWritable()) {
            return false;
        }

        $this->normalizeOptions($options);
        $this->normalizeKey($key);
        $args = new ArrayObject(array(
            'key'     => & $key,
            'value'   => & $value,
            'options' => & $options,
        ));

        try {
            $eventRs = $this->triggerPre(__FUNCTION__, $args);
            if ($eventRs->stopped()) {
                return $eventRs->last();
            }

            $internalKey = $options['namespace'] . $baseOptions->getNamespaceSeparator() . $key;
            if (!$this->memcached->get($internalKey)) {
                throw new Exception\ItemNotFoundException(
                    "Key '{$internalKey}' doesn't exist"
                );
            }

            $result = $this->memcached->replace($internalKey, $value, $options['ttl']);

            if ($result === false) {
                $type = is_object($value) ? get_class($value) : gettype($value);
                throw new Exception\RuntimeException(
                    "Memcached::replace('{$internalKey}', <{$type}>, {$options['ttl']}) failed"
                );
            }

            return $this->triggerPost(__FUNCTION__, $args, $result);
        } catch (\Exception $e) {
            return $this->triggerException(__FUNCTION__, $args, $e);
        }
    }

    /**
     * Remove an item.
     *
     * Options:
     *  - namespace <string> optional
     *    - The namespace to use (Default: namespace of object)
     *  - ignore_missing_items <boolean> optional
     *    - Throw exception on missing item or return false
     *
     * @param  string $key
     * @param  array $options
     * @return boolean
     * @throws Exception
     *
     * @triggers removeItem.pre(PreEvent)
     * @triggers removeItem.post(PostEvent)
     * @triggers removeItem.exception(ExceptionEvent)
     */
    public function removeItem($key, array $options = array())
    {
        $baseOptions = $this->getOptions();
        if (!$baseOptions->getWritable()) {
            return false;
        }

        $this->normalizeOptions($options);
        $this->normalizeKey($key);
        $args = new ArrayObject(array(
            'key'     => & $key,
            'value'   => & $value,
            'options' => & $options,
        ));

        try {
            $eventRs = $this->triggerPre(__FUNCTION__, $args);
            if ($eventRs->stopped()) {
                return $eventRs->last();
            }

            $internalKey = $options['namespace'] . $baseOptions->getNamespaceSeparator() . $key;

            $result = $this->memcached->delete($internalKey);

            if ($result === false) {
                if (!$options['ignore_missing_items']) {
                    throw new Exception\ItemNotFoundException("Key '{$internalKey}' not found");
                }
            }
            $result = true;

            return $this->triggerPost(__FUNCTION__, $args, $result);
        } catch (\Exception $e) {
            return $this->triggerException(__FUNCTION__, $args, $e);
        }
    }

    /**
     * Increment an item.
     *
     * Options:
     *  - namespace <string> optional
     *    - The namespace to use (Default: namespace of object)
     *  - ignore_missing_items <boolean> optional
     *    - Throw exception on missing item or return false
     *
     * @param  string $key
     * @param  int $value
     * @param  array $options
     * @return int|boolean The new value or false on failure
     * @throws Exception
     *
     * @triggers incrementItem.pre(PreEvent)
     * @triggers incrementItem.post(PostEvent)
     * @triggers incrementItem.exception(ExceptionEvent)
     */
    public function incrementItem($key, $value, array $options = array())
    {
        $baseOptions = $this->getOptions();
        if (!$baseOptions->getWritable()) {
            return false;
        }

        $this->normalizeOptions($options);
        $this->normalizeKey($key);
        $args = new ArrayObject(array(
            'key'     => & $key,
            'options' => & $options,
        ));

        try {
            $eventRs = $this->triggerPre(__FUNCTION__, $args);
            if ($eventRs->stopped()) {
                return $eventRs->last();
            }

            $internalKey = $options['namespace'] . $baseOptions->getNamespaceSeparator() . $key;
            $value       = (int)$value;
            $newValue    = $this->memcached->increment($internalKey, $value);
            if ($newValue === false) {
                if ($this->memcached->get($internalKey)!==false) {
                    throw new Exception\RuntimeException("Memcached::increment('{$internalKey}', {$value}) failed");
                } elseif (!$options['ignore_missing_items']) {
                    throw new Exception\ItemNotFoundException(
                        "Key '{$internalKey}' not found"
                    );
                }

                $this->addItem($key, $value, $options);
                $newValue = $value;
            }

            return $this->triggerPost(__FUNCTION__, $args, $newValue);
        } catch (\Exception $e) {
            return $this->triggerException(__FUNCTION__, $args, $e);
        }
    }

    /**
     * Decrement an item.
     *
     * Options:
     *  - namespace <string> optional
     *    - The namespace to use (Default: namespace of object)
     *  - ignore_missing_items <boolean> optional
     *    - Throw exception on missing item or return false
     *
     * @param  string $key
     * @param  int $value
     * @param  array $options
     * @return int|boolean The new value or false or failure
     * @throws Exception
     *
     * @triggers decrementItem.pre(PreEvent)
     * @triggers decrementItem.post(PostEvent)
     * @triggers decrementItem.exception(ExceptionEvent)
     */
    public function decrementItem($key, $value, array $options = array())
    {
        $baseOptions = $this->getOptions();
        if (!$baseOptions->getWritable()) {
            return false;
        }

        $this->normalizeOptions($options);
        $this->normalizeKey($key);
        $args = new ArrayObject(array(
            'key'     => & $key,
            'options' => & $options,
        ));

        try {
            $eventRs = $this->triggerPre(__FUNCTION__, $args);
            if ($eventRs->stopped()) {
                return $eventRs->last();
            }

            $internalKey = $options['namespace'] . $baseOptions->getNamespaceSeparator() . $key;
            $value       = (int)$value;
            $newValue    = $this->memcached->decrement($internalKey, $value);
            if ($newValue === false) {
                if ($this->memcached->get($internalKey)!==false) {
                    throw new Exception\RuntimeException("Memcached::decrement('{$internalKey}', {$value}) failed");
                } elseif (!$options['ignore_missing_items']) {
                    throw new Exception\ItemNotFoundException(
                        "Key '{$internalKey}' not found"
                    );
                }

                $this->addItem($key, -$value, $options);
                $newValue = -$value;
            }

            return $this->triggerPost(__FUNCTION__, $args, $newValue);
        } catch (\Exception $e) {
            return $this->triggerException(__FUNCTION__, $args, $e);
        }
    }

    /* non-blocking */

    /**
     * Get items that were marked to delay storage for purposes of removing blocking
     *
     * Options:
     *  - namespace <string> optional
     *    - The namespace to use (Default: namespace of object)
     *  - select <array> optional
     *    - An array of the information the returned item contains
     *      (Default: array('key', 'value'))
     *  - callback <callback> optional
     *    - An result callback will be invoked for each item in the result set.
     *    - The first argument will be the item array.
     *    - The callback does not have to return anything.
     *
     * @param  array $keys
     * @param  array $options
     * @return bool
     * @throws Exception
     *
     * @triggers getDelayed.pre(PreEvent)
     * @triggers getDelayed.post(PostEvent)
     * @triggers getDelayed.exception(ExceptionEvent)
     */
    public function getDelayed(array $keys, array $options = array())
    {
        $baseOptions = $this->getOptions();
        if ($this->stmtActive) {
            throw new Exception\RuntimeException('Statement already in use');
        } elseif (!$baseOptions->getReadable()) {
            return false;
        } elseif (!$keys) {
            return true;
        }

        $this->normalizeOptions($options);
        if (isset($options['callback']) && !is_callable($options['callback'], false)) {
            throw new Exception\InvalidArgumentException('Invalid callback');
        }
        if (!isset($options['select'])) {
            $options['select'] = array('key', 'value');
        }

        $args = new ArrayObject(array(
            'key'     => & $key,
            'options' => & $options,
        ));

        try {
            $eventRs = $this->triggerPre(__FUNCTION__, $args);
            if ($eventRs->stopped()) {
                return $eventRs->last();
            }

            $prefix = $options['namespace'] . $baseOptions->getNamespaceSeparator();

            // init search keys
            $search = array();
            foreach ($keys as $key) {
                $search[] = $prefix.$key;
            }

            // we don't need the CAS token
            $withCas = false;

            // redirect callback
            if (isset($options['callback'])) {
                $cb = function (MemcachedResource $memc, array &$item) use (&$options, $baseOptions) {
                    $select = & $options['select'];

                    // handle selected key
                    if (in_array('key', $select)) {
                        $namespaceSeparator = $baseOptions->getNamespaceSeparator();
                        $prefixL = strlen($options['namespace'] . $namespaceSeparator);
                        $item['key'] = substr($item['key'], $prefixL);
                    } else {
                        unset($item['key']);
                    }

                    // handle selected value
                    if (!in_array('value', $select)) {
                        unset($item['value']);
                    }

                    call_user_func($options['callback'], $item);
                };

                if (!$this->memcached->getDelayed($search, false, $cb)) {
                    throw new Exception\RuntimeException(
                        'Memcached::getDelayed(<array>, false, <callback>) failed'
                    );
                }
            } else {
                if (!$this->memcached->getDelayed($search)) {
                    throw new Exception\RuntimeException(
                        'Memcached::getDelayed(<array>) failed'
                    );
                }

                $this->stmtActive  = true;
                $this->stmtOptions = &$options;
            }

            $result = true;
            return $this->triggerPost(__FUNCTION__, $args, $result);
        } catch (\Exception $e) {
            return $this->triggerException(__FUNCTION__, $args, $e);
        }
    }

    /**
     * Fetches the next item from result set
     *
     * @return array|boolean The next item or false
     * @see    fetchAll()
     *
     * @triggers fetch.pre(PreEvent)
     * @triggers fetch.post(PostEvent)
     * @triggers fetch.exception(ExceptionEvent)
     */
    public function fetch()
    {
        if (!$this->stmtActive) {
            return false;
        }

        $args = new ArrayObject();

        try {
            $eventRs = $this->triggerPre(__FUNCTION__, $args);
            if ($eventRs->stopped()) {
                return $eventRs->last();
            }

            $result = $this->memcached->fetch();
            if (!empty($result)) {
                $select = & $this->stmtOptions['select'];

                // handle selected key
                if (in_array('key', $select)) {
                    $namespaceSeparator = $this->getOptions()->getNamespaceSeparator();
                    $prefixL = strlen($this->stmtOptions['namespace'] . $namespaceSeparator);
                    $result['key'] = substr($result['key'], $prefixL);
                } else {
                    unset($result['key']);
                }

                // handle selected value
                if (!in_array('value', $select)) {
                    unset($result['value']);
                }

            } else {
                // clear stmt
                $this->stmtActive  = false;
                $this->stmtOptions = null;
            }

            return $this->triggerPost(__FUNCTION__, $args, $result);
        } catch (\Exception $e) {
            return $this->triggerException(__FUNCTION__, $args, $e);
        }
    }

    /**
     * FetchAll
     *
     * @throws Exception
     * @return array
     */
    public function fetchAll()
    {
        $prefixL = strlen($this->stmtOptions['namespace'] . $this->getOptions()->getNamespaceSeparator());

        $result = $this->memcached->fetchAll();

        if ($result === false) {
            throw new Exception\RuntimeException("Memcached::fetchAll() failed");
        }

        $select = $this->stmtOptions['select'];

        foreach ($result as &$elem) {
            if (in_array('key', $select)) {
                $elem['key'] = substr($elem['key'], $prefixL);
            } else {
                unset($elem['key']);
            }
        }

        return $result;
    }

    /* cleaning */

    /**
     * Clear items off all namespaces.
     *
     * Options:
     *  - No options available for this adapter
     *
     * @param  int $mode Matching mode (Value of Zend\Cache\Storage\Adapter::MATCH_*)
     * @param  array $options
     * @return boolean
     * @throws Exception
     * @see clearByNamespace()
     *
     * @triggers clear.pre(PreEvent)
     * @triggers clear.post(PostEvent)
     * @triggers clear.exception(ExceptionEvent)
     */
    public function clear($mode = self::MATCH_EXPIRED, array $options = array())
    {
        if (!$this->getOptions()->getWritable()) {
            return false;
        }

        $this->normalizeOptions($options);
        $this->normalizeMatchingMode($mode, self::MATCH_EXPIRED, $options);
        $args = new ArrayObject(array(
            'mode'    => & $mode,
            'options' => & $options,
        ));

        try {
            $eventRs = $this->triggerPre(__FUNCTION__, $args);
            if ($eventRs->stopped()) {
                return $eventRs->last();
            }

            $result = $this->memcached->flush();

            return $this->triggerPost(__FUNCTION__, $args, $result);
        } catch (\Exception $e) {
            return $this->triggerException(__FUNCTION__, $args, $e);
        }
    }

    /* status */

    /**
     * Get capabilities
     *
     * @return Capabilities
     *
     * @triggers getCapabilities.pre(PreEvent)
     * @triggers getCapabilities.post(PostEvent)
     * @triggers getCapabilities.exception(ExceptionEvent)
     */
    public function getCapabilities()
    {
        $args = new ArrayObject();

        try {
            $eventRs = $this->triggerPre(__FUNCTION__, $args);
            if ($eventRs->stopped()) {
                return $eventRs->last();
            }

            if ($this->capabilities === null) {
                $this->capabilityMarker = new stdClass();
                $this->capabilities     = new Capabilities(
                    $this->capabilityMarker,
                    array(
                        'supportedDatatypes' => array(
                            'NULL'     => true,
                            'boolean'  => true,
                            'integer'  => true,
                            'double'   => true,
                            'string'   => true,
                            'array'    => true,
                            'object'   => 'object',
                            'resource' => false,
                        ),
                        'supportedMetadata'  => array(),
                        'maxTtl'             => 0,
                        'staticTtl'          => false,
                        'tagging'            => false,
                        'ttlPrecision'       => 1,
                        'useRequestTime'     => false,
                        'expiredRead'        => false,
                        'namespaceIsPrefix'  => true,
                        'namespaceSeparator' => $this->getOptions()->getNamespaceSeparator(),
                        'iterable'           => false,
                        'clearAllNamespaces' => true,
                        'clearByNamespace'   => false,
                    )
                );
            }

            return $this->triggerPost(__FUNCTION__, $args, $this->capabilities);
        } catch (\Exception $e) {
            return $this->triggerException(__FUNCTION__, $args, $e);
        }
    }

    /**
     * Get storage capacity.
     *
     * @param  array $options
     * @return array|boolean Capacity as array or false on failure
     *
     * @triggers getCapacity.pre(PreEvent)
     * @triggers getCapacity.post(PostEvent)
     * @triggers getCapacity.exception(ExceptionEvent)
     */
    public function getCapacity(array $options = array())
    {
        $args = new ArrayObject(array(
            'options' => & $options,
        ));

        try {
            $eventRs = $this->triggerPre(__FUNCTION__, $args);
            if ($eventRs->stopped()) {
                return $eventRs->last();
            }

            $mem    = array_pop($this->memcached->getStats());
            $result = array(
                'free'  => $mem['limit_maxbytes'] - $mem['bytes'],
                'total' => $mem['limit_maxbytes'],
            );
            return $this->triggerPost(__FUNCTION__, $args, $result);
        } catch (\Exception $e) {
            return $this->triggerException(__FUNCTION__, $args, $e);
        }
    }

    /* internal */

}
