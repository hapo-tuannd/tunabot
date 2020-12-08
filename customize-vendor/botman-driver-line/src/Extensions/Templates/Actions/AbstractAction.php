<?php

namespace BotMan\Drivers\Line\Extensions\Templates\Actions;

abstract class AbstractAction implements \JsonSerializable
{
    /** @var string */
    protected $label = '';

    /** @var string */
    protected $value = '';

    /**
     * @param string $label
     *
     * @return static
     */
    public static function create($label)
    {
        return new static($label);
    }

    /**
     * @param string $label
     */
    public function __construct($label)
    {
        $this->label = $label;
    }

    /**
     * Set button value.
     *
     * @param string $value
     * @return $this
     */
    public function value($value)
    {
        $this->value = $value;

        return $this;
    }

    /**
     * @return array
     */
    abstract public function toArray();

    /**
     * @return array
     */
    public function jsonSerialize()
    {
        return $this->toArray();
    }
}
