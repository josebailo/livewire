<?php

namespace Livewire\ComponentConcerns;

use Livewire\Livewire;
use Illuminate\Support\Str;
use Livewire\ImplicitlyBoundMethod;
use Illuminate\Database\Eloquent\Model;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Exceptions\NonPublicComponentMethodCall;
use Livewire\Exceptions\PublicPropertyNotFoundException;
use Livewire\Exceptions\MissingFileUploadsTraitException;
use Livewire\Exceptions\CannotBindToModelDataWithoutValidationRuleException;

trait HandlesActions
{
    public function syncInput($name, $value, $rehash = true)
    {
        $propertyName = $this->beforeFirstDot($name);

        throw_if(
            $this->{$propertyName} instanceof Model && $this->missingRuleFor($name),
            new CannotBindToModelDataWithoutValidationRuleException($name, $this::getName())
        );

        $this->callBeforeAndAfterSyncHooks($name, $value, function ($name, $value) use ($propertyName, $rehash) {
            throw_unless(
                $this->propertyIsPublicAndNotDefinedOnBaseClass($propertyName),
                new PublicPropertyNotFoundException($propertyName, $this::getName())
            );

            if ($this->containsDots($name)) {
                //strip model variable name
                $keyName = $this->afterFirstDot($name);
                // get model attribute to be filled
                $targetKey = $this->beforeFirstDot($keyName);

                $results = [];
                //get existing data
                $results[$targetKey] = data_get($this->{$propertyName}, $targetKey, []);
                //merge new data
                data_set($results, $keyName, $value);

                //assign data
                data_set($this->{$propertyName}, $targetKey, $results[$targetKey]);
            } else {
                $this->{$name} = $value;
            }

            $rehash && $this->rehashProperty($name);
        });
    }

    protected function callBeforeAndAfterSyncHooks($name, $value, $callback)
    {
        $propertyName = Str::before(Str::studly($name), '.');
        $keyAfterFirstDot = Str::contains($name, '.') ? Str::after($name, '.') : null;

        $beforeMethod = 'updating'.$propertyName;
        $afterMethod = 'updated'.$propertyName;


        $this->updating($name, $value);

        if (method_exists($this, $beforeMethod)) {
            $this->{$beforeMethod}($value, $keyAfterFirstDot);
        }

        $callback($name, $value);

        $this->updated($name, $value);

        if (method_exists($this, $afterMethod)) {
            $this->{$afterMethod}($value, $keyAfterFirstDot);
        }
    }

    public function callMethod($method, $params = [])
    {
        switch ($method) {
            case '$sync':
                $prop = array_shift($params);
                $this->syncInput($prop, head($params));

                return;

            case '$set':
                $prop = array_shift($params);
                $this->syncInput($prop, head($params), $rehash = false);

                return;

            case '$toggle':
                $prop = array_shift($params);
                $this->syncInput($prop, ! $this->{$prop}, $rehash = false);

                return;

            case '$refresh':
                return;
        }

        if (! method_exists($this, $method)) {
            throw_if($method === 'startUpload', new MissingFileUploadsTraitException($this));

            throw new MethodNotFoundException($method, $this::getName());
        }

        throw_unless($this->methodIsPublicAndNotDefinedOnBaseClass($method), new NonPublicComponentMethodCall($method));

        $returned = ImplicitlyBoundMethod::call(app(), [$this, $method], $params);

        Livewire::dispatch('action.returned', $this, $method, $returned);
    }

    protected function methodIsPublicAndNotDefinedOnBaseClass($methodName)
    {
        return collect((new \ReflectionClass($this))->getMethods(\ReflectionMethod::IS_PUBLIC))
            ->reject(function ($method) {
                // The "render" method is a special case. This method might be called by event listeners or other ways.
                if ($method === 'render') {
                    return false;
                }

                return $method->class === self::class;
            })
            ->pluck('name')
            ->search($methodName) !== false;
    }
}
