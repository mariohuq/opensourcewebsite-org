<?php

namespace app\modules\bot\models;

/**
 * Class UserState
 *
 * @package app\modules\bot\models
 */
class UserState
{
    private $fields = [];

    private function __construct()
    {
        $this->fields['intermediate'] = [];
    }

    public function getName()
    {
        return $this->fields['name'] ?? null;
    }

    /**
     * @param string|null $value
     *
     * @return mixed|null
     */
    public function setName(string $value = null)
    {
        if (is_null($value)) {
            unset($this->fields['name']);
        } else {
            $this->fields['name'] = $value;
        }
    }

    /**
     * @param string $name
     * @param null $defaultValue
     *
     * @return mixed|null
     */
    public function getIntermediateField(string $name, $defaultValue = null)
    {
        return $this->fields['intermediate'][$name] ?? $defaultValue;
    }

    /**
     * @param array $values
     */
    public function setIntermediateFields($values)
    {
        foreach ($values as $name => $value) {
            $this->fields['intermediate'][$name] = $value;
        }
    }

    /**
     * @param string $name
     * @param $value
     */
    public function setIntermediateField(string $name, $value = null)
    {
        if (is_null($value)) {
            unset($this->fields['intermediate'][$name]);
        } else {
            $this->fields['intermediate'][$name] = $value;
        }
    }

    /**
     * @param string $name
     *
     * @return bool
     */
    public function isIntermediateFieldExists(string $name)
    {
        return array_key_exists($name, $this->fields['intermediate']);
    }

    public function save(User $user)
    {
        $user->state = json_encode($this->fields);

        return $user->save();
    }

    public function reset()
    {
        $this->fields = [];
    }

    public static function fromUser(User $user)
    {
        $state = new UserState();

        if (!empty($user->state)) {
            $state->fields = json_decode($user->state, true);
        }

        return $state;
    }
}
