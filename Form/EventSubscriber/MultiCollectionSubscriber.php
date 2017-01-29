<?php

namespace Ruwork\AdminBundle\Form\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Form\Exception\UnexpectedTypeException;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;

class MultiCollectionSubscriber implements EventSubscriberInterface
{
    /**
     * @var array
     */
    private $configs;

    /**
     * @var callable
     */
    private $preSetDataResolver;

    /**
     * @var callable
     */
    private $preSubmitResolver;

    /**
     * @var bool
     */
    private $allowAdd;

    /**
     * @var bool
     */
    private $allowDelete;

    /**
     * @var bool
     */
    private $deleteEmpty;

    /**
     * @param array          $configs
     * @param callable|array $preSetDataResolver
     * @param callable       $preSubmitResolver
     * @param bool           $allowAdd
     * @param bool           $allowDelete
     * @param bool           $deleteEmpty
     */
    public function __construct(
        array $configs,
        $preSetDataResolver,
        callable $preSubmitResolver,
        $allowAdd,
        $allowDelete,
        $deleteEmpty
    ) {
        $this->configs = $configs;
        $this->preSetDataResolver = $preSetDataResolver;
        $this->preSubmitResolver = $preSubmitResolver;
        $this->allowAdd = $allowAdd;
        $this->allowDelete = $allowDelete;
        $this->deleteEmpty = $deleteEmpty;
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            FormEvents::PRE_SET_DATA => 'preSetData',
            FormEvents::PRE_SUBMIT => 'preSubmit',
            FormEvents::SUBMIT => ['onSubmit', 50],
        ];
    }

    /**
     * @param FormEvent $event
     *
     * @throws UnexpectedTypeException
     */
    public function preSetData(FormEvent $event)
    {
        $form = $event->getForm();
        $data = $event->getData();

        if (null === $data) {
            $data = [];
        }

        if (!is_array($data) && !($data instanceof \Traversable && $data instanceof \ArrayAccess)) {
            throw new UnexpectedTypeException($data, 'array or (\Traversable and \ArrayAccess)');
        }

        // First remove all rows
        foreach ($form as $name => $child) {
            $form->remove($name);
        }

        // Then add all rows again in the correct order
        foreach ($data as $name => $value) {
            if (is_array($this->preSetDataResolver)) {
                $configName = $this->preSetDataResolver[get_class($value)];
            } else {
                $configName = ($this->preSetDataResolver)($value);
            }

            $this->formAddValue($form, $name, $configName);
        }
    }

    /**
     * @param FormEvent $event
     */
    public function preSubmit(FormEvent $event)
    {
        $form = $event->getForm();
        $data = $event->getData();

        if ($data instanceof \Traversable && $data instanceof \ArrayAccess) {
            @trigger_error('Support for objects implementing both \Traversable and \ArrayAccess is deprecated since version 3.1 and will be removed in 4.0. Use an array instead.',
                E_USER_DEPRECATED);
        }

        if (!is_array($data) && !($data instanceof \Traversable && $data instanceof \ArrayAccess)) {
            $data = [];
        }

        // Remove all empty rows
        if ($this->allowDelete) {
            foreach ($form as $name => $child) {
                if (!isset($data[$name])) {
                    $form->remove($name);
                }
            }
        }

        // Add all additional rows
        if ($this->allowAdd) {
            foreach ($data as $name => $value) {
                if (!$form->has($name)) {
                    $this->formAddValue($form, $name, ($this->preSubmitResolver)($value));
                }
            }
        }
    }

    /**
     * @param FormEvent $event
     *
     * @throws UnexpectedTypeException
     */
    public function onSubmit(FormEvent $event)
    {
        $form = $event->getForm();
        $data = $event->getData();

        // At this point, $data is an array or an array-like object that already contains the
        // new entries, which were added by the data mapper. The data mapper ignores existing
        // entries, so we need to manually unset removed entries in the collection.

        if (null === $data) {
            $data = [];
        }

        if (!is_array($data) && !($data instanceof \Traversable && $data instanceof \ArrayAccess)) {
            throw new UnexpectedTypeException($data, 'array or (\Traversable and \ArrayAccess)');
        }

        if ($this->deleteEmpty) {
            $previousData = $event->getForm()->getData();
            foreach ($form as $name => $child) {
                $isNew = !isset($previousData[$name]);

                // $isNew can only be true if allowAdd is true, so we don't
                // need to check allowAdd again
                if ($child->isEmpty() && ($isNew || $this->allowDelete)) {
                    unset($data[$name]);
                    $form->remove($name);
                }
            }
        }

        // The data mapper only adds, but does not remove items, so do this
        // here
        if ($this->allowDelete) {
            $toDelete = [];

            foreach ($data as $name => $child) {
                if (!$form->has($name)) {
                    $toDelete[] = $name;
                }
            }

            foreach ($toDelete as $name) {
                unset($data[$name]);
            }
        }

        $event->setData($data);
    }

    /**
     * @param FormInterface $form
     * @param string        $name
     * @param string        $configName
     */
    private function formAddValue(FormInterface $form, $name, $configName)
    {
        $form
            ->add($name, $this->configs[$configName]['type'], array_replace([
                'property_path' => '['.$name.']',
            ], $this->configs[$configName]['options']));

        $form[$name]
            ->add('_prototype_name', HiddenType::class, [
                'mapped' => false,
                'data' => $configName,
            ]);
    }
}
