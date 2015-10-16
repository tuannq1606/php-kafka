<?php
/* vim: set expandtab tabstop=4 shiftwidth=4 softtabstop=4 foldmethod=marker: */
// +---------------------------------------------------------------------------
// | SWAN [ $_SWANBR_SLOGAN_$ ]
// +---------------------------------------------------------------------------
// | Copyright $_SWANBR_COPYRIGHT_$
// +---------------------------------------------------------------------------
// | Version  $_SWANBR_VERSION_$
// +---------------------------------------------------------------------------
// | Licensed ( $_SWANBR_LICENSED_URL_$ )
// +---------------------------------------------------------------------------
// | $_SWANBR_WEB_DOMAIN_$
// +---------------------------------------------------------------------------

namespace Kafka\Protocol\Fetch;

use Kafka\Exception;
use Kafka\Exception\OutOfRange;
use Kafka\Log;
use Decoder;

/**
+------------------------------------------------------------------------------
* Kafka protocol since Kafka v0.8
+------------------------------------------------------------------------------
*
* @package
* @version $_SWANBR_VERSION_$
* @copyright Copyleft
* @author $_SWANBR_AUTHOR_$
+------------------------------------------------------------------------------
*/

class MessageSet implements \Iterator
{
    // {{{ members

    /**
     * kafka socket object
     *
     * @var mixed
     * @access private
     */
    private $stream = null;

    /**
     * messageSet size
     *
     * @var float
     * @access private
     */
    private $messageSetSize = 0;

    /**
     * validByteCount
     *
     * @var float
     * @access private
     */
    private $validByteCount = 0;

    /**
     * messageSet offset
     *
     * @var int
     * @access private
     */
    private $offset = 0;

    /**
     * valid
     *
     * @var mixed
     * @access private
     */
    private $valid = false;

    /**
     * partition object
     *
     * @var Partition
     * @access private
     */
    private $partition = null;

    /**
     * request fetch context
     *
     * @var array
     */
    private $context = array();

    private $current = null;

    // }}}
    // {{{ functions
    // {{{ public function __construct()

    /**
     * __construct
     *
     * @param Partition $partition
     * @param array     $context
     *
     * @access public
     */
    public function __construct(Partition $partition, $context = array())
    {
        $this->stream = $partition->getStream();
        $this->partition = $partition;
        $this->context   = $context;
        $this->messageSetSize = $this->getMessageSetSize();
        Log::log("messageSetSize: {$this->messageSetSize}", LOG_INFO);
    }

    // }}}
    // {{{ public function current()

    /**
     * current
     *
     * @access public
     * @return Message
     */
    public function current()
    {
        return $this->current;
    }

    // }}}
    // {{{ public function key()

    /**
     * key
     *
     * @access public
     * @return string
     */
    public function key()
    {
        return $this->validByteCount;
    }

    // }}}
    // {{{ public function rewind()

    /**
     * implements Iterator function
     *
     * @access public
     * @return integer
     */
    public function rewind()
    {
        $this->valid = $this->loadNextMessage();
    }

    // }}}
    // {{{ public function valid()

    /**
     * implements Iterator function
     *
     * @access public
     * @return integer
     */
    public function valid()
    {
        if (!$this->valid) {
            $this->partition->setMessageOffset($this->offset);

            // one partition iterator end
            Helper\Helper::onPartitionEof($this->partition);
        }

        return $this->valid;
    }

    // }}}
    // {{{ public function next()

    /**
     * implements Iterator function
     *
     * @access public
     * @return integer
     */
    public function next()
    {
        $this->valid = $this->loadNextMessage();
    }

    // }}}
    // {{{ protected function getMessageSetSize()

    /**
     * get message set size
     *
     * @access protected
     * @return integer
     */
    protected function getMessageSetSize()
    {
        // read message size
        $data = $this->stream->read(4, true);
        $data = Decoder::unpack(Decoder::BIT_B32, $data);
        $size = array_shift($data);
        if ($size <= 0) {
            throw new OutOfRange($size . ' is not a valid message size');
        }

        return $size;
    }

    // }}}
    // {{{ public function loadNextMessage()

    /**
     * load next message
     *
     * @access public
     * @return bool
     */
    public function loadNextMessage()
    {
        if ($this->validByteCount >= $this->messageSetSize) {
            return false;
        }

        try {
            if ($this->validByteCount + 12 > $this->messageSetSize) {
                // read socket buffer dirty data
                $this->stream->read($this->messageSetSize - $this->validByteCount);
                return false;
            }
            $offset = $this->stream->read(8, true);
            $this->offset  = Decoder::unpack(Decoder::BIT_B64, $offset);
            $messageSize = $this->stream->read(4, true);
            $messageSize = Decoder::unpack(Decoder::BIT_B32, $messageSize);
            $messageSize = array_shift($messageSize);
            $this->validByteCount += 12;
            if (($this->validByteCount + $messageSize) > $this->messageSetSize) {
                // read socket buffer dirty data
                $this->stream->read($this->messageSetSize - $this->validByteCount);
                return false;
            }
            $msg  = $this->stream->read($messageSize, true);
            $this->current = new Message($msg);
        } catch (Exception $e) {
            Log::log("already fetch: {$this->validByteCount}, {$e->getMessage()}", LOG_INFO);
            return false;
        }

        $this->validByteCount += $messageSize;

        return true;
    }

    // }}}
    // {{{ public function messageOffset()

    /**
     * current message offset in producer
     *
     * @return int
     */
    public function messageOffset()
    {
        return $this->offset;
    }

    // }}}
    // }}}
}
