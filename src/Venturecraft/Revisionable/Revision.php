<?php

namespace Venturecraft\Revisionable;

use Illuminate\Database\Eloquent\Model as Eloquent;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Log;

/**
 * Revision.
 *
 * Base model to allow for revision history on
 * any model that extends this model
 *
 * (c) Venture Craft <http://www.venturecraft.com.au>
 *
 * @property int $id
 * @property string $revisionable_type
 * @property int $revisionable_id
 * @property int|null $user_id
 * @property string $key
 * @property string|null $old_value
 * @property string|null $new_value
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Model|\Eloquent $revisionable
 * @method static \Illuminate\Database\Eloquent\Builder|\Venturecraft\Revisionable\Revision newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\Venturecraft\Revisionable\Revision newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|\Venturecraft\Revisionable\Revision query()
 * @method static \Illuminate\Database\Eloquent\Builder|\Venturecraft\Revisionable\Revision whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\Venturecraft\Revisionable\Revision whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\Venturecraft\Revisionable\Revision whereKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\Venturecraft\Revisionable\Revision whereNewValue($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\Venturecraft\Revisionable\Revision whereOldValue($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\Venturecraft\Revisionable\Revision whereRevisionableId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\Venturecraft\Revisionable\Revision whereRevisionableType($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\Venturecraft\Revisionable\Revision whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\Venturecraft\Revisionable\Revision whereUserId($value)
 * @mixin \Eloquent
 */
class Revision extends Eloquent
{
    /**
     * @var string
     */
    public $table = 'revisions';

    /**
     * @var array
     */
    protected $revisionFormattedFields = [];

    /**
     * Revisionable.
     *
     * Grab the revision history for the model that is calling
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function revisionable()
    {
        return $this->morphTo();
    }

    /**
     * Field Name
     *
     * Returns the field that was updated, in the case that it's a foreign key
     * denoted by a suffix of "_id", then "_id" is simply stripped
     *
     * @return string field
     */
    public function fieldName()
    {
        if ($formatted = $this->formatFieldName($this->key)) {
            return $formatted;
        } elseif (strpos($this->key, '_id')) {
            return str_replace('_id', '', $this->key);
        } else {
            return $this->key;
        }
    }

    /**
     * Format field name.
     *
     * Allow overrides for field names.
     *
     * @param $key
     *
     * @return bool
     */
    private function formatFieldName($key)
    {
        $relatedModel = $this->getRevisionableType();
        $relatedModel = new $relatedModel;
        $revisionFormattedFieldNames = $relatedModel->getRevisionFormattedFieldNames();

        if (isset($revisionFormattedFieldNames[$key])) {
            return $revisionFormattedFieldNames[$key];
        }

        return false;
    }

    /**
     * Old Value.
     *
     * Grab the old value of the field, if it was a foreign key
     * attempt to get an identifying name for the model.
     *
     * @return string old value
     */
    public function oldValue()
    {
        return $this->getValue('old');
    }


    /**
     * New Value.
     *
     * Grab the new value of the field, if it was a foreign key
     * attempt to get an identifying name for the model.
     *
     * @return string old value
     */
    public function newValue()
    {
        return $this->getValue('new');
    }


    /**
     * Responsible for actually doing the grunt work for getting the
     * old or new value for the revision.
     *
     * @param string $which old or new
     *
     * @return string value
     */
    private function getValue($which = 'new')
    {
        $whichValue = $which . '_value';

        // First find the main model that was updated
        $mainModel = $this->getRevisionableType();
        // Load it, WITH the related model
        if (class_exists($mainModel)) {
            $mainModel = new $mainModel;

            try {
                if ($this->isRelated()) {
                    $relatedModel = $this->getRelatedModel();

                    // Now we can find out the namespace of of related model
                    if (!method_exists($mainModel, $relatedModel)) {
                        $relatedModel = camel_case($relatedModel); // for cases like published_status_id
                        if (!method_exists($mainModel, $relatedModel)) {
                            throw new \Exception('Relation ' . $relatedModel . ' does not exist for ' . $mainModel);
                        }
                    }
                    $relatedClass = $mainModel->$relatedModel()->getRelated();

                    // Finally, now that we know the namespace of the related model
                    // we can load it, to find the information we so desire
                    $item = $relatedClass::find($this->$whichValue);

                    if (is_null($this->$whichValue) || $this->$whichValue == '') {
                        $item = new $relatedClass;

                        return $item->getRevisionNullString();
                    }
                    if (!$item) {
                        $item = new $relatedClass;

                        return $this->format($this->key, $item->getRevisionUnknownString());
                    }

                    // Check if model use RevisionableTrait
                    if (method_exists($item, 'identifiableName')) {
                        // see if there's an available mutator
                        $mutator = 'get' . studly_case($this->key) . 'Attribute';
                        if (method_exists($item, $mutator)) {
                            return $this->format($item->$mutator($this->key), $item->identifiableName());
                        }

                        return $this->format($this->key, $item->identifiableName());
                    }
                }

                if ($value = $this->morph($whichValue)) {
                    return $this->format($this->key, $value);
                }
            } catch (\Exception $e) {
                // Just a fail-safe, in the case the data setup isn't as expected
                // Nothing to do here.
                Log::info('Revisionable: ' . $e);
            }

            // if there was an issue
            // or, if it's a normal value

            $mutator = 'get' . studly_case($this->key) . 'Attribute';
            if (method_exists($mainModel, $mutator)) {
                return $this->format($this->key, $mainModel->$mutator($this->$whichValue));
            }
        }

        return $this->format($this->key, $this->$whichValue);
    }

    /**
     * Return true if the key is for a related model.
     *
     * @return bool
     */
    private function isRelated()
    {
        $isRelated = false;
        $idSuffix = '_id';
        $pos = strrpos($this->key, $idSuffix);

        if ($pos !== false
            && strlen($this->key) - strlen($idSuffix) === $pos
        ) {
            $isRelated = true;
        }

        return $isRelated;
    }

    /**
     * Return the name of the related model.
     *
     * @return string
     */
    private function getRelatedModel()
    {
        $idSuffix = '_id';

        return substr($this->key, 0, strlen($this->key) - strlen($idSuffix));
    }

    /**
     * User Responsible.
     *
     * @return \Illuminate\Contracts\Auth\Authenticatable|bool user responsible for the change
     */
    public function userResponsible()
    {
        if (empty($this->user_id)) {
            return false;
        }
        if (class_exists($class = '\Cartalyst\Sentry\Facades\Laravel\Sentry')
            || class_exists($class = '\Cartalyst\Sentinel\Laravel\Facades\Sentinel')
        ) {
            return $class::findUserById($this->user_id);
        } else {
            $userModel = app('config')->get('auth.model');

            if (empty($userModel)) {
                $guard = config('auth.defaults.guard');
                $provider = config("auth.guards.{$guard}.provider");
                $userModel = config("auth.providers.{$provider}.model");

                if (empty($userModel)) {
                    return false;
                }
            }
            if (!class_exists($userModel)) {
                return false;
            }

            return $userModel::find($this->user_id);
        }
    }

    /**
     * Returns the object we have the history of
     *
     * @return Object|false
     */
    public function historyOf()
    {
        if (class_exists($class = $this->getRevisionableType())) {
            return $class::find($this->revisionable_id);
        }

        return false;
    }

    /*
     * Examples:
    array(
        'public' => 'boolean:Yes|No',
        'minimum'  => 'string:Min: %s'
    )
     */
    /**
     * Format the value according to the $revisionFormattedFields array.
     *
     * @param  $key
     * @param  $value
     *
     * @return string formatted value
     */
    public function format($key, $value)
    {
        $relatedModel = $this->getRevisionableType();
        $relatedModel = new $relatedModel;
        $revisionFormattedFields = $relatedModel->getRevisionFormattedFields();

        if (isset($revisionFormattedFields[$key])) {
            return FieldFormatter::format($key, $value, $revisionFormattedFields);
        } else {
            return $value;
        }
    }

    protected function getRevisionableType()
    {
        if (method_exists(Relation::class, 'getMorphedModel')) {
            $type = Relation::getMorphedModel($this->revisionable_type);
        }

        return $type ?? $this->revisionable_type;
    }

    public function morph($value)
    {
        $relatedModel = $this->getRevisionableType();
        $relatedModel = new $relatedModel;
        $polymorphic = $relatedModel->getRevisionPolymorphicFields() ?? [];

        if (!in_array($this->key, $polymorphic) && !isset($polymorphic[$this->key])) {
            return false;
        }

        $data = json_decode($this->$value);

        if (!$data) {
            return null;
        }

        $polymorphicClass = Relation::getMorphedModel($data->type);

        if (!method_exists($polymorphicClass, 'identifiableName')) {
            return $data->id;
        }

        return $polymorphicClass::withTrashed()->find($data->id)->identifiableName();
    }
}
