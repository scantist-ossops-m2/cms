<?php

namespace Statamic\Support\Traits;

trait HasDirtyState
{
    /**
     * Is the item or property on the item dirty?
     *
     * @param  null|string|array  $properties
     * @return bool
     */
    public function isDirty($properties = null)
    {
        $currentValues = $this->getDirtyArray();
        $originalValues = $this->getOriginal();

        if (! $properties) {
            return json_encode($currentValues) !== json_encode($originalValues);
        }

        if (! is_array($properties)) {
            $properties = [$properties];
        }

        foreach ($properties as $property) {
            if (! array_key_exists($property, $currentValues)) {
                $property = 'data.'.$property;
            }

            if (array_get($currentValues, $property) !== array_get($originalValues, $property)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is the item or property on the item clean?
     *
     * @param  null|string|array  $properties
     * @return bool
     */
    public function isClean($properties = null)
    {
        return ! $this->isDirty($properties);
    }

    /**
     * Get the original (non-dirty) values
     *
     * @param  null|string|array  $properties
     * @return bool
     */
    public function getOriginal()
    {
        return ($fresh = $this->fresh()) ? $fresh->getDirtyArray() : [];
    }
}
