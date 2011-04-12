<?php

namespace DrSlump\Protobuf\Codec;

use DrSlump\Protobuf;

class JsonIndexed extends Json
    implements Protobuf\CodecInterface
{

    /**
     * @static
     * @return Binary
     */
    static public function getInstance()
    {
        static $instance;

        if (NULL === $instance) {
            $instance = new self();
        }

        return $instance;
    }

    public function encodeMessage(Protobuf\Message $message)
    {
        $descriptor = $message::descriptor();

        $index = '';
        $data = array();
        foreach ($descriptor->getFields() as $tag=>$field) {

            $empty = !$message->_has($tag);
            if ($field->isRequired() && $empty) {
                throw new \RuntimeException(
                    'Message ' . get_class($message) . ' field tag ' . $tag . ' is required but has not value'
                );
            }

            if ($empty) {
                continue;
            }

            $index .= $this->i2c($tag + 48);

            $value = $message->_get($tag);

            if ($field->isRepeated()) {
                $repeats = array();
                foreach ($value as $val) {
                    if ($field->getType() !== Protobuf::TYPE_MESSAGE) {
                        $repeats[] = $val;
                    } else {
                        $repeats[] = $this->encodeMessage($val);
                    }
                }
                $data[] = $repeats;
            } else {
                if ($field->getType() === Protobuf::TYPE_MESSAGE) {
                    $data[] = $this->encodeMessage($value);
                } else {
                    $data[] = $value;
                }
            }
        }

        // Insert the index at first element
        array_unshift($data, $index);

        return $data;
    }

    public function decodeMessage(\DrSlump\Protobuf\Message $message, $data)
    {
        // Get message descriptor
        $descriptor = $message::descriptor();

        // Split the index in UTF8 characters
        preg_match_all('/./u', $data[0], $chars);

        $chars = $chars[0];
        for ($i=1; $i<count($data); $i++) {

            $k = $this->c2i($chars[$i-1]) - 48;
            $v = $data[$i];

            $field = $descriptor->getField($k);

            if (NULL === $field) {
                // Unknown
                $unknown = new Json\Unknown($k, gettype($v), $v);
                $message->addUnknown($unknown);
                continue;
            }

            $message->_set($k, $v);

            if ($field->getType() === Protobuf::TYPE_MESSAGE) {
                $nested = $field->getReference();
                $nested = new $nested;
                $v = $this->decodeMessage($nested, $v);
            }

            if ($field->isRepeated()) {
                $message->_add($k, $v);
            } else {
                $message->_set($k, $v);
            }
        }

        return $message;
    }

    protected function i2c($codepoint)
    {
        return $codepoint < 128
               ? chr($codepoint)
               : html_entity_decode("&#$codepoint;", ENT_NOQUOTES, 'UTF-8');
    }

    protected function c2i($char)
    {
        $value = ord($char[0]);
        if ($value < 128) return $value;

        if ($value < 224) {
            return (($value % 32) * 64) + (ord($char[1]) % 64);
        } else {
            return (($value % 16) * 4096) +
                   ((ord($char[1]) % 64) * 64) +
                   (ord($char[2]) % 64);
        }
    }

}
