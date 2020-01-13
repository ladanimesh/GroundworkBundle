<?php
declare(strict_types=1);

namespace Lankerd\GroundworkBundle\Handler;

use DomainException;
use Exception;
use Lankerd\GroundworkBundle\Helper\DataHelperInterface;
use Lankerd\GroundworkBundle\Helper\QueryHelper;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Inflector\Inflector;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Stopwatch\Stopwatch;

/**
 * Class DataHandler
 *
 * @package Lankerd\GroundworkBundle\Handler
 * @author  Julian Lankerd <julianlankerd@gmail.com>
 */
class DataHandler
{
    /**
     * @var \Lankerd\GroundworkBundle\Helper\DataHelperInterface
     */
    protected $dataHelper;

    /**
     * @var \Symfony\Component\Form\FormFactoryInterface
     */
    protected $formFactory;

    /**
     * @var \Symfony\Component\Serializer\Serializer
     */
    protected $serializer;

    /**
     * @var \Lankerd\GroundworkBundle\Helper\QueryHelper
     */
    protected $queryHelper;

    /**
     * @var array
     */
    protected $requestData;

    /**
     * @var array
     */
    protected $globalIdentifiers;

    /**
     * @var array
     */
    protected $response;

    /**
     * DataHandler constructor.
     *
     * @param \Lankerd\GroundworkBundle\Helper\DataHelperInterface $dataHelper
     * @param \Symfony\Component\Form\FormFactoryInterface $formFactory
     * @param \Symfony\Component\Serializer\Serializer $serializer
     * @param \Lankerd\GroundworkBundle\Helper\QueryHelper $queryHelper
     */
    public function __construct(
        DataHelperInterface $dataHelper,
        FormFactoryInterface $formFactory,
        Serializer $serializer,
        QueryHelper $queryHelper
    ) {
        $this->dataHelper = $dataHelper;
        $this->formFactory = $formFactory;
        $this->serializer = $serializer;
        $this->queryHelper = $queryHelper;
    }


    /**
     * @param Request $request
     * @return array
     */
    public function requestHandler(Request $request)
    {
        $stopwatch = new Stopwatch();
        $stopwatch->start('transaction');

        /*Store the request data into a property for re-usability purposes*/
        $this->setRequestData($request);
        /*Access the formatted request data, and store it in a variable for later*/
        $data = $this->getRequestData();
        /*Store the request data into a property for re-usability purposes*/
        $this->indexActions($data);

        $this->response['responseTime'] = $stopwatch->stop('transaction');
        return $this->response;
    }

    /**
     * This will run through an array,
     * and check to see if it has any
     * actions that are supported by the
     * system.
     */
    public function indexActions(array $data)
    {
        foreach ($data['actions'] as $action => $entities) {
            $dataHelper = $this->dataHelper;
            $queryHelper = $this->queryHelper;

            /**
             * LOAD ORDER
             */
            if ($action === 'loadOrder') {
                foreach ($entities as $entity) {
                    $this->queryHelper->persistEntity($this->globalIdentifiers[$entity]);
                }
                continue;
            }

            foreach ($entities as $entityName => $entityCollection) {
                $fullEntityNamespace = $dataHelper::ENTITY_NAMESPACE.$entityName;
                $entityProperties = $dataHelper->getObjectProperties($fullEntityNamespace);

                foreach ($entityCollection as $entityUniqueIdentifier => $entityFields) {
                    /**
                     * GET
                     */
                    if($action === 'get'){
                        $this->globalIdentifiers[$entityUniqueIdentifier] = $this->queryHelper->getEntityRepository($fullEntityNamespace)->findBy($entityFields);
                    }

                    /**
                     * CREATE
                     */
                    if ($action === 'create') {
                        // Lets see if we need to check if the record already exists?
                        if(!empty($entityFields['checkIfExists'])) {
                            $item = $this->queryHelper->getEntityRepository($fullEntityNamespace)->findBy($entityFields['checkIfExists']);
                            if(!empty($item)) {
                                throw new RuntimeException($entityName . ' Already Exists');
                            }
                        }

                        $entity = new $fullEntityNamespace();
                        $form = $this->dynamicForm($entity, $entityFields);
                        $form->submit($entityFields);

                        /*Check if the current $form has been submitted, and is valid.*/
                        if ($form->isSubmitted() && $form->isValid()) {
                            $this->globalIdentifiers[$entityUniqueIdentifier] = $entity;
                            $this->queryHelper->persistEntity($entity);
                            
                            $this->response['data'] = $entity->getId();
                            $this->response['code'] = 200;
                            $this->response['message'] = $entityName . ' Created';
                        }else{
                            throw new RuntimeException($entityName . ' had an Error. '.$form->getErrors()->current()->getMessage());
                        }
                    }

                    /**
                     * UPDATE
                     */
                    if ($action === 'update') {

                        if(empty($entityFields['findBy']) && empty($entityFields['updateRecord'])) return;

                        $entity = $this->queryHelper->getEntityRepository($fullEntityNamespace)->findBy($entityFields['findBy']);
                        $form = $this->dynamicForm($entity[0], $entityFields['updateRecord']);
                        $form->submit($entityFields['updateRecord']);

                        /*Check if the current $form has been submitted, and is valid.*/
                        if ($form->isSubmitted() && $form->isValid()) {
                            $this->globalIdentifiers[$entityUniqueIdentifier] = $entity[0];
                            $this->queryHelper->persistEntity($entity[0]);
                            $this->response['code'] = 200;
                            $this->response['message'] = $entityName . ' Updated';
                        }else{
                            throw new RuntimeException($form->getErrors()->current()->getMessage());
                        }
                    }

                    /**
                     * DELETE
                     */
                    if($action === 'delete') {

                        if(empty($entityFields['findOneBy'])) return;

                        $entity = $this->queryHelper->getEntityRepository($fullEntityNamespace)->findOneBy($entityFields['findOneBy']);
                        if($entity != null) {
                            $this->queryHelper->remove($entity);
                            $this->response['code'] = 200;
                            $this->response['message'] = $entityName . ' Removed';
                        } else {
                            throw new RuntimeException('Unable to delete record');
                        }
                    }

                    /**
                     * CONNECT
                     */
                    if ($action === 'connect') {
                        $entityMetadata = $this->queryHelper->getClassMetadata($fullEntityNamespace);
                        $entity = $this->globalIdentifiers[$entityUniqueIdentifier];
                        foreach ($entityFields as $fieldName => $fieldValue) {
                            if ($entityMetadata->hasAssociation($fieldName)) {
                                /*This will loop through all of the entityMethods*/
                                foreach ($entityProperties[$fieldName] as $method) {
                                    foreach ($fieldValue as $value) {
                                        if (false !== stripos($method, 'set')) {
                                            $entity->$method($this->globalIdentifiers[$value]);
                                        }
                                        if (false !== stripos($method, 'add')) {
                                            $entity->$method($this->globalIdentifiers[$value]);
                                        }
                                    }
                                }
                            }else{
                                throw new RuntimeException($fieldName.' is not a valid globalIdentifier, try looking at your request and ensure');
                            }
                        }
                        $this->queryHelper->persistEntity($entity, false);
                        $this->globalIdentifiers[$entityUniqueIdentifier] = $entity;
                    }

                    /**
                     * OUTPUT
                     */
                    if ($action === 'response') {
                        $entityResults = [];
                        foreach($data['actions']['response'] as $outputEntity => $request) {
                            foreach($request as $key => $items) {
                                if( $key === 'getter'){
                                    if(!$items['get']) continue;
                                    $entityResults[$items['get']] = $this->getter($dataHelper::ENTITY_NAMESPACE.$outputEntity, $items);
                                }

                                // find($id, $lockMode = null, $lockVersion = null)
                                if( $key === 'find'){
                                    if(!$items['id']) continue;
                                    $entityResults[$items['get']] = $this->find($dataHelper::ENTITY_NAMESPACE.$outputEntity, $items);
                                }

                                // findOneBy(array $criteria, array $orderBy = null)
                                if($key === 'findOneBy') {
                                    if(!$items['criteria']) continue;
                                    $entityResults[$items['get']] = $this->findOneBy($dataHelper::ENTITY_NAMESPACE.$outputEntity, $items);
                                }

                                // findAll()
                                if($key === 'findAll') {
                                    if(!$items['get']) continue;
                                    $entityResults[$items['get']] = $this->findAll($dataHelper::ENTITY_NAMESPACE.$outputEntity, $items);
                                }

                                // findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
                                if($key === 'findBy') {
                                    if(!$items['criteria']) continue;
                                    $entityResults[$items['get']] = $this->findBy($dataHelper::ENTITY_NAMESPACE.$outputEntity, $items);
                                }

                                /**
                                 * @todo need to write in a way for custom repo calls to be made.
                                */
//                                if($key === 'customRepository') {
//                                    dump($items['criteria']);
//                                    die;
//                                    if(!$items['criteria']) continue;
//                                    $entityResults[$items['get']] = $this->findBy($dataHelper::ENTITY_NAMESPACE.$outputEntity, $items);
//                                }
                            }
                        }

                        $this->response['data'] = $entityResults;
                    }
                }
            }
        }
    }

    public function getter( $outputEntity, $items )
    {
        $getter = '';

        $vars = $this->getterVars($items);
        $includes = !empty($vars['includes']) ? $vars['includes'] : ''; unset($vars['includes']);
        $excludes = !empty($vars['excludes']) ? $vars['excludes'] : ''; unset($vars['excludes']);

        // Take out the include and exclude
        foreach($vars as $key => $item) {
            if ($key === 'get') {
                $getter = 'get' . $item;
            } else {
                $criteria = [$key => $item];
            }
        }

        return $this->serializeFix($this->queryHelper->getEntityRepository($outputEntity)->findOneBy($criteria)->$getter(), $excludes, $includes);
    }

    public function find( $outputEntity, $items )
    {
        $vars = $this->getterVars($items);
        $includes = !empty($vars['includes']) ? $vars['includes'] : ''; unset($vars['includes']);
        $excludes = !empty($vars['excludes']) ? $vars['excludes'] : ''; unset($vars['excludes']);

        return $this->serializeFix($this->queryHelper->getEntityRepository($outputEntity)->find($vars['id']), $excludes, $includes);
    }

    public function findOneBy( $outputEntity, $items )
    {
        $vars = $this->getterVars($items);
        $includes = !empty($vars['includes']) ? $vars['includes'] : ''; unset($vars['includes']);
        $excludes = !empty($vars['excludes']) ? $vars['excludes'] : ''; unset($vars['excludes']);

        return $this->serializeFix($this->queryHelper->getEntityRepository($outputEntity)->findOneBy($vars['criteria'], $vars['orderBy']), $excludes, $includes);
    }

    public function findAll( $outputEntity, $items )
    {
        $vars = $this->getterVars($items);
        $includes = !empty($vars['includes']) ? $vars['includes'] : ''; unset($vars['includes']);
        $excludes = !empty($vars['excludes']) ? $vars['excludes'] : ''; unset($vars['excludes']);

        return $this->serializeFix($this->queryHelper->getEntityRepository($outputEntity)->findAll(), $excludes, $includes);
    }

    public function findBy( $outputEntity, $items )
    {
        $vars = $this->getterVars($items);
        $includes = !empty($vars['includes']) ? $vars['includes'] : ''; unset($vars['includes']);
        $excludes = !empty($vars['excludes']) ? $vars['excludes'] : ''; unset($vars['excludes']);

        return $this->serializeFix($this->queryHelper->getEntityRepository($outputEntity)->findBy($vars['criteria'], $vars['orderBy'], $vars['limit'], $vars['offset']), $excludes, $includes);
    }

    public function serializeFix( $object, $excludes = [], $includes = [] )
    {
        $normalizer = new ObjectNormalizer();
        $encoder = new JsonEncoder();
        $serializer = new Serializer([$normalizer], [$encoder]);

        if(!empty($includes)) {
            return json_decode($serializer->serialize($object, 'json', [AbstractNormalizer::ATTRIBUTES => $includes]), true);
        } elseif( !empty($excludes)) {
            return json_decode($serializer->serialize($object, 'json', [AbstractNormalizer::IGNORED_ATTRIBUTES => $excludes]), true);
        } else {
            return json_decode($serializer->serialize($object, 'json'), true);
        }
    }

    public function getterVars($items)
    {
        $return = $items;

        // If we have includes we should not have excludes even if it is passed.
        if(!empty($items['includes'])) {
            $return['includes'] = $items['includes'];
            unset($items['includes']);
            unset($return['excludes']);
        }

        if(!empty($items['excludes']) && empty($return['includes'])) {
            $return['excludes'] = $items['excludes'];
            unset($items['excludes']);
        }

        if(!empty($items['orderBy'])) {
            $return['orderBy'] = $items['orderBy'];
            unset($items['orderBy']);
        }

        return $return;
    }

    public function dynamicForm( $entity, $data )
    {
        /*Create form with corresponding Entity paired to it*/
        $class = $this->queryHelper->getClassMetadata(get_class($entity));
        $form = $this->formFactory->create('Symfony\Component\Form\Extension\Core\Type\FormType', $entity, ['csrf_protection' => false]);
        foreach($data as $key => $value) {
            if(!$class->hasAssociation($key)){
                $fieldArray = $class->getFieldMapping($key);
                if($fieldArray['type'] == 'time'){
                    $form->add($key, TimeType::class,['widget' => 'single_text']);
                } elseif ($fieldArray['type'] == 'date'){
                    $form->add($key, DateType::class,['widget' => 'single_text']);
                } elseif ($fieldArray['type'] == 'datetime'){
                    $form->add($key, DateTimeType::class,['widget' => 'single_text']);
                }else{
                    $form->add($key);
                }
            }else{
                $form->add($key);
            }
        }

        return $form;
    }

    /**
     * @param Request $request
     */
    public function setRequestData(Request $request): void
    {
        $this->requestData = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array
     */
    public function getRequestData(): array
    {
        return $this->requestData;
    }

    /**
     * Check to see if the specified class exists
     *
     * @param string $class
     *
     * @throws \RuntimeException
     */
    public function setClass(string $class)
    {
        $this->dataHelper->setClassName($class);
    }



    // Will deprecate code below this line
    /**
     * THIS NEEDS TO BE REWRITTEN WHEN I AM MORE CONSCIOUS. TOO TIRED TO WRITE GOOD CODE.
     *
     * @param Request $request
     *
     * @return int
     * @throws \Exception
     */
    public function updateRecord(Request $request): int
    {
        $dataHelper = $this->dataHelper;
        /*Unpack and decode data from $request in order to obtain form information.*/
        $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        if (empty($data['targetEntity']) || empty($data['updateValues'])) {
            throw new RuntimeException('Data that was sent is incorrectly formatted!');
        }
        /*Instantiate a new User object for us to insert the $request form data into.*/
        if (count(
                $entity = $this->queryHelper->getEntityRepository($dataHelper->getClassPath())->findBy(
                    $data['targetEntity']
                )
            ) === 1) {
            $entity = $entity[0];
        } else {
            throw new RuntimeException(
                'Data that was sent is too vauge to accuratly update, please supply more detail in order to narrow the search scope'
            );
        }
        /*Create form with corresponding Entity paired to it*/
        $bindingMethod = [];
        $objectMethods = $this->dataHelper->getObjectProperties($entity);
        $class = $this->queryHelper->getClassMetadata(get_class($entity));
        foreach ($data['updateValues'] as $key => $updateValue) {
            if (array_key_exists(ucfirst($key), $objectMethods)) {
                if($class->hasAssociation($key)){
                    $relationArray = $class->getAssociationMapping($key);
                    $updateValue = $this->queryHelper->getEntityRepository($relationArray['targetEntity'])->find($updateValue);
                }
                foreach ($objectMethods[ucfirst($key)] as $objectMethod) {
                    if (false !== stripos($objectMethod, 'set')) {
                        $entity->$objectMethod($updateValue);
                    }
                }
            }
        }
        $this->queryHelper->persistEntity($entity);
        return $entity->getId();
    }
    /**
     * julianlankerd@gmail.com, build a filtration system, and actually use $request!
     *
     * @return string
     * @throws \Exception
     */
    public function getAllValues(Request $request): string
    {
        $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $filterEntity = array();
        $orderBy = array();
        $limit = null;
        $findFunction = 'findBy';
        if (!empty($data['filterEntity'])) {
            $filterEntity = $data['filterEntity'];
        }
        if (!empty($data['orderBy'])) {
            $orderBy = $data['orderBy'];
        }
        if (!empty($data['limit'])) {
            $limit = $data['limit'];
            if($limit == 1) {
                $findFunction = 'findOneBy';
            }
        }
        $dataQuery = $this->queryHelper->getEntityRepository('App:'.$this->dataHelper->getClassName())->$findFunction(
            $filterEntity, // where
            $orderBy,      // orderBy
            $limit         // limit
        );
        return $this->serializer->serialize(
            $dataQuery,
            'json',
            [
                ObjectNormalizer::ENABLE_MAX_DEPTH => true,
                'circular_reference_handler' => function ($object) {
                    return $object->getId();
                },
                'max_depth_handler' => function ($innerObject, $outerObject, string $attributeName, string $format = null, array $context = []) {
                    if(method_exists($innerObject,'getId')){
                        return $innerObject->getId();
                    } else {
                        return '';
                    }
                }
            ]
        );
    }
    /**
     * @param \Symfony\Component\HttpFoundation\Request $request
     *
     * @throws Exception
     */
    public function connectRecords(Request $request): void
    {
        /*Grab the Doctrine Entity Manager so that we can process our Entity to the database.*/
        $queryHelper = $this->queryHelper;
        /*Unpack and decode data from $request in order to obtain form information.*/
        $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        /*There should be information that can be used to find the specified "primaryEntity" in the database.*/
        $primaryEntity = $data['primaryEntity'];
        /*There should be information that can be used to find the multiple specified "secondaryEntities" in the database.*/
        $secondaryEntities = $data['secondaryEntities'];
        /**
         * Placing this into a try-catch is definitely a wise call
         * considering we are stuffing kinda questionable stuff into the
         * findBy.
         * Query for the $primaryEntity, so that we can begin to set relationships
         * to the $primaryEntity. This is the target we will be setting database
         * relationships towards.
         */
        try {
            $primaryEntity = $queryHelper->getEntityRepository('App:'.key($primaryEntity))->findBy(
                $primaryEntity[key($primaryEntity)]
            );
        } catch (Exception $e) {
            throw $e;
        }
        /*Check if there are many objects that have been returned to the return*/
        $primaryEntity = $this->dataHelper->hasOneValue($primaryEntity, 'primaryEntity');
        $objectProperties = $this->dataHelper->getObjectProperties($primaryEntity);
        foreach ($secondaryEntities as $entityName => $entityData) {
            /**
             * Check to see if there are multiple
             * of the same entity in the request.
             * NOTE: If there should be multiple
             * entities sent, the key MUST be denoted
             * with a prepended underscore (Example: "Entity, Entity_2, etc..."),
             * the value after the underscore MUST BE UNIQUE!!!
             */
            if (false !== strpos($entityName, '_')) {
                /**
                 * Break the unique underscore with identifier
                 * off of the string, and carry on using the
                 * normalized name of the entity in question.
                 */
                $entityName = explode('_', $entityName)[0];
            }
            $entityName = $this->dataHelper->singularize(ucfirst($entityName));
            /**
             * Check if any of the properties passed are an array.
             * If the property is, an exception will be thrown
             * because the functionality does not currently exist.
             * Why would someone wish to pass an array in a property?
             * Collections or associations. Someone may wish to search
             * for information to pair up based on a certain relationship.
             * Sorry for any temporary inconveniences! It'll be usable soon!
             * julianlankerd@gmail.com needs to build association functionality capable in the "connector"
             */
            foreach ($entityData as $index => $entityDatum) {
                if (is_array($entityDatum)) {
                    throw new DomainException(
                        'It appears an array was passed as a property! This functionality is not yet available, but will be coming soon!'
                    );
                }
            }
            try {
                $returnedValue = $queryHelper->getEntityRepository('App:'.$entityName)->findBy($entityData);
            } catch (Exception $e) {
                throw new RuntimeException($e->getMessage());
            }
            /*Check if there are many objects that have been returned to the return*/
            $entity = $this->dataHelper->hasOneValue($returnedValue, $entityName);
            $bindingMethod = null;
            foreach ($objectProperties[ucfirst($entityName)] as $objectMethod) {
                if (false !== stripos($objectMethod, 'add')) {
                    $bindingMethod = $objectMethod;
                }
                if (false !== stripos($objectMethod, 'set')) {
                    $bindingMethod = $objectMethod;
                }
            }
            $primaryEntity->$bindingMethod($entity);
        }
        $queryHelper->persistEntity($primaryEntity);
    }
    public function createRecord(Request $request)
    {
        $queryHelper = $this->queryHelper;
        $dataHelper = $this->dataHelper;
        /*Unpack and decode data from $request in order to obtain form information.*/
        $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        /*Instantiate a new User object for us to insert the $request form data into.*/
        $entity = $dataHelper->getEntity();
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $childEntity = null;
                $singularKey = (Inflector::singularize($key));
                if(is_array($singularKey)){
                    $singularKey = $singularKey[1];
                } else{
                    $singularKey = $key;
                }
                if(!$this->checkArrayContainArray($value)){
                    unset($value['searchField']);
                    /* $storedEntity is an entity within the json form besides the parent. */
                    $childEntity = $queryHelper->getEntityRepository('App:'.ucfirst($singularKey))->findBy($value);
                }
                if($childEntity == null){
                    $parentClassName = $this->dataHelper->getClassName();
                    $id = $this->createChildRecord($singularKey, $value, $parentClassName);
                    $childEntity = $queryHelper->getEntityRepository('App:'.ucfirst($singularKey))->findBy(['id'=>$id]);
                }
                $parentEntity = $dataHelper->getObjectProperties($entity);
                if (array_key_exists(ucfirst($singularKey), $parentEntity)) {
                    foreach ($parentEntity[ucfirst($singularKey)] as $method) {
                        if (false !== stripos($method, 'set')) {
                            $entity->$method($childEntity[0]);
                        }
                        if (false !== stripos($method, 'add')) {
                            foreach ($childEntity as $value) {
                                $entity->$method($value);
                            }
                        }
                    }
                }
                unset($data[$key]);
            }
        }
        /*Create form with corresponding Entity paired to it*/
        $class = $this->queryHelper->getClassMetadata(get_class($entity));
        $form = $this->formFactory->create('Symfony\Component\Form\Extension\Core\Type\FormType', $entity, ['csrf_protection' => false]);
        foreach($data as $key => $value) {
            if(!$class->hasAssociation($key)){
                $fieldArray = $class->getFieldMapping($key);
                if($fieldArray['type'] == 'time'){
                    $form->add($key, TimeType::class,['widget' => 'single_text']);
                } elseif ($fieldArray['type'] == 'date'){
                    $form->add($key, DateType::class,['widget' => 'single_text']);
                } elseif ($fieldArray['type'] == 'datetime'){
                    $form->add($key, DateTimeType::class,['widget' => 'single_text']);
                }else{
                    $form->add($key);
                }
            }else{
                $form->add($key);
            }
        }
        /*Submit $data that was unpacked from the $response into the $form.*/
        $form->submit($data);
        /*Check if the current $form has been submitted, and is valid.*/
        if ($form->isSubmitted() && $form->isValid()) {
            $this->queryHelper->persistEntity($entity);
            return $entity->getId();
        }
        throw new RuntimeException($form->getErrors()->current()->getMessage());
    }
    private function checkArrayContainArray($array) {
        if(!isset($array['searchField'])) {
            foreach($array as $value){
                if(is_array($value)) {
                    return true;
                }
            }
        }
        return false;
    }
    private function createChildRecord($key, $data, $parentClassName = null) {
        $queryHelper = $this->queryHelper;
        $dataHelper = $this->dataHelper;
        $this->setClass($key);
        $parentKey = $key;
        $entity = $dataHelper->getEntity();
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $childEntity = null;
                $singularKey = (Inflector::singularize($key));
                if(is_array($singularKey)){
                    $singularKey = $singularKey[1];
                } else{
                    $singularKey = $key;
                }
                if(!$this->checkArrayContainArray($value)){
                    unset($value['searchField']);
                    /* $storedEntity is an entity within the json form besides the parent. */
                    $childEntity = $queryHelper->getEntityRepository('App:'.ucfirst($singularKey))->findBy($value);
                }
                if($childEntity == null){
                    $id = $this->createChildRecord($singularKey, $value, $parentKey);
                    $childEntity = $queryHelper->getEntityRepository('App:'.ucfirst($singularKey))->findBy(['id'=>$id]);
                }
                $parentEntity = $dataHelper->getObjectProperties($entity);
                if (array_key_exists(ucfirst($singularKey), $parentEntity)) {
                    foreach ($parentEntity[ucfirst($singularKey)] as $method) {
                        if (false !== stripos($method, 'set')) {
                            $entity->$method($childEntity[0]);
                        }
                        if (false !== stripos($method, 'add')) {
                            foreach ($childEntity as $value) {
                                $entity->$method($value);
                            }
                        }
                    }
                }
                unset($data[$key]);
            }
        }
        /*Create form with corresponding Entity paired to it*/
        $class = $this->queryHelper->getClassMetadata(get_class($entity));
        $form = $this->formFactory->create('Symfony\Component\Form\Extension\Core\Type\FormType', $entity, ['csrf_protection' => false]);
        foreach($data as $key => $value) {
            if(!$class->hasAssociation($key)){
                $fieldArray = $class->getFieldMapping($key);
                if($fieldArray['type'] == 'time'){
                    $form->add($key, TimeType::class,['widget' => 'single_text']);
                } elseif ($fieldArray['type'] == 'date'){
                    $form->add($key, DateType::class,['widget' => 'single_text']);
                } elseif ($fieldArray['type'] == 'datetime'){
                    $form->add($key, DateTimeType::class,['widget' => 'single_text']);
                }else{
                    $form->add($key);
                }
            }else{
                $form->add($key);
            }
        }
        /*Submit $data that was unpacked from the $response into the $form.*/
        $form->submit($data);
        /*Check if the current $form has been submitted, and is valid.*/
        if ($form->isSubmitted() && $form->isValid()) {
            $this->queryHelper->persistEntity($entity);
            $response = $entity->getId();
            if ($parentClassName != null) {
                $this->setClass($parentClassName);
            }
            return $response;
        }
    }
    /**
     * @return string
     * @throws \Exception
     */
    public function deleteRecord(Request $request)
    {
        $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $dataObj = $this->queryHelper->getEntityRepository($this->dataHelper->getClassPath())->find($data['id']);
        if($dataObj){
            $this->queryHelper->removeRecord($dataObj);
            $result = 'success';
        } else {
            $result = 'failed';
        }
        return ['result'=>$result];
    }
}
