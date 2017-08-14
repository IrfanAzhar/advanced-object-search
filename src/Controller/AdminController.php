<?php

namespace AdvancedObjectSearchBundle\Controller;

use AdvancedObjectSearchBundle\Model\SavedSearch;
use AdvancedObjectSearchBundle\Service;
use Pimcore\Bundle\AdminBundle\Controller\Admin\External\AdminerController;
use Pimcore\Model\Object;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Class AdminController
 * @Route("/admin")
 */
class AdminController extends AdminerController {

    /**
     * @param Request $request
     * @Route("/get-fields")
     */
    public function getFieldsAction(Request $request) {

        $type = strip_tags($request->get("type"));

        $allowInheritance = false;

        switch ($type) {
            case "class":
                $classId = intval($request->get("class_id"));
                $definition = \Pimcore\Model\Object\ClassDefinition::getById($classId);
                $allowInheritance = $definition->getAllowInherit();
                break;

            case "fieldcollection":
                $key = strip_tags($request->get("key"));
                $definition = Object\Fieldcollection\Definition::getByKey($key);
                $allowInheritance = false;
                break;

            case "objectbrick":
                $key = strip_tags($request->get("key"));
                $definition = Object\Objectbrick\Definition::getByKey($key);

                $classId = intval($request->get("class_id"));
                $classDefinition = \Pimcore\Model\Object\ClassDefinition::getById($classId);
                $allowInheritance = $classDefinition->getAllowInherit();

                break;

            default:
                throw new \Exception("Invalid type '$type''");


        }

        $service = new Service();
        $fieldSelectionInformationEntries = $service->getFieldSelectionInformationForClassDefinition($definition, $allowInheritance);

        $fields = [];
        foreach($fieldSelectionInformationEntries as $entry) {
            $fields[] = $entry->toArray();
        }

        $this->json(['data' => $fields]);
    }

    /**
     * @param Request $request
     * @Route("/grid-proxy")
     */
    public function gridProxyAction(Request $request) {
        $requestedLanguage = $request->get("language");
        if ($requestedLanguage) {
            if ($requestedLanguage != "default") {
                $request->setLocale($requestedLanguage);
            }
        } else {
            $requestedLanguage = $request->getLocale();
        }

        if ($request->get("data")) {
            $this->forward("grid-proxy", "object", "admin");
        } else {

            // get list of objects
            $class = Object\ClassDefinition::getById($request->get("classId"));
            $className = $class->getName();

            $fields = array();
            if ($request->get("fields")) {
                $fields = $request->get("fields");
            }

            $start = 0;
            $limit = 20;
            if ($request->get("limit")) {
                $limit = $request->get("limit");
            }
            if ($request->get("start")) {
                $start = $request->get("start");
            }

            $listClass = "\\Pimcore\\Model\\Object\\" . ucfirst($className) . "\\Listing";


            //get ID list from ES Service
            $service = new Service($this->getUser());
            $data = json_decode($request->get("filter"), true);
            $results = $service->doFilter($data['classId'], $data['conditions']['filters'], $data['conditions']['fulltextSearchTerm'], $start, $limit);

            $total = $service->extractTotalCountFromResult($results);
            $ids = $service->extractIdsFromResult($results);

            /**
             * @var $list \Pimcore\Model\Object\Listing
             */
            $list = new $listClass();
            $list->setObjectTypes(["object", "folder", "variant"]);

            if(!empty($ids)) {
                $list->setCondition("o_id IN (" . implode(",", $ids) . ")");
                $list->setOrderKey(" FIELD(o_id, " . implode(",", $ids) . ")", false);
            } else {
                $list->setCondition("1=2");
            }

            $list->load();

            $objects = array();
            foreach ($list->getObjects() as $object) {
                $o = Object\Service::gridObjectData($object, $fields, $requestedLanguage);
                $objects[] = $o;
            }
            $this->json(array("data" => $objects, "success" => true, "total" => $total));

        }
    }

    /**
     * @param Request $request
     * @Route("/get-batch-jobs")
     */
    public function getBatchJobsAction(Request $request)
    {
        if ($request->get("language")) {
            $request->setLocale($request->get("language"));
        }

        $class = Object\ClassDefinition::getById($request->get("classId"));

        //get ID list from ES Service
        $service = new Service($this->getUser());
        $data = json_decode($request->get("filter"), true);
        $results = $service->doFilter($data['classId'], $data['conditions']['filters'], $data['conditions']['fulltextSearchTerm']);

        $ids = $service->extractIdsFromResult($results);

        $className = $class->getName();
        $listClass = "\\Pimcore\\Model\\Object\\" . ucfirst($className) . "\\Listing";
        $list = new $listClass();
        $list->setObjectTypes(["object", "folder", "variant"]);
        $list->setCondition("o_id IN (" . implode(",", $ids) . ")");
        $list->setOrderKey(" FIELD(o_id, " . implode(",", $ids) . ")", false);

        if ($request->get("objecttype")) {
            $list->setObjectTypes(array($request->get("objecttype")));
        }

        $jobs = $list->loadIdList();

        $this->json(array("success"=>true, "jobs"=>$jobs));
    }


    protected function getCsvFile($fileHandle) {
        return PIMCORE_SYSTEM_TEMP_DIRECTORY . "/" . $fileHandle . ".csv";
    }

    /**
     * @param Request $request
     * @Route("/get-export-jobs")
     */
    public function getExportJobsAction(Request $request) {
        if ($request->get("language")) {
            $request->setLocale($request->get("language"));
        }

        //get ID list from ES Service
        $service = new Service($this->getUser());
        $data = json_decode($request->get("filter"), true);

        $results = $service->doFilter(
            $data['classId'],
            $data['conditions']['filters'],
            $data['conditions']['fulltextSearchTerm'],
            0,
            9999 // elastic search cannot export more results dann 9999 in one request
        );

        $ids = $service->extractIdsFromResult($results);
        $jobs = array_chunk($ids, 20);

        $fileHandle = uniqid("export-");
        file_put_contents($this->getCsvFile($fileHandle), "");
        $this->json(array("success"=>true, "jobs"=> $jobs, "fileHandle" => $fileHandle));
    }

    /**
     * @param Request $request
     * @Route("/save")
     */
    public function saveAction(Request $request) {

        $data = $request->get("data");
        $data = json_decode($data);

        $id = (intval($request->get("id")));
        if($id) {
            $savedSearch = SavedSearch::getById($id);
        } else {
            $savedSearch = new SavedSearch();
            $savedSearch->setOwner($this->getUser());
        }

        $savedSearch->setName($data->settings->name);
        $savedSearch->setDescription($data->settings->description);
        $savedSearch->setCategory($data->settings->category);
        $savedSearch->setSharedUserIds($data->settings->shared_users);

        $config = ['classId' => $data->classId, "gridConfig" => $data->gridConfig, "conditions" => $data->conditions];
        $savedSearch->setConfig(json_encode($config));

        $savedSearch->save();

        $this->json(["success" => true, "id" => $savedSearch->getId()]);
    }

    /**
     * @param Request $request
     * @Route("/delete")
     */
    public function deleteAction(Request $request) {

        $id = intval($request->get("id"));
        $savedSearch = SavedSearch::getById($id);

        if($savedSearch) {
            $savedSearch->delete();
            $this->json(["success" => true, "id" => $savedSearch->getId()]);
        }

    }

    /**
     * @param Request $request
     * @Route("/find")
     */
    public function findAction(Request $request) {

        $user = $this->getUser();

        $query = $request->get("query");
        if ($query == "*") {
            $query = "";
        }

        $query = str_replace("%", "*", $query);

        $offset = intval($request->get("start"));
        $limit = intval($request->get("limit"));

        $offset = $offset ? $offset : 0;
        $limit = $limit ? $limit : 50;

        $searcherList = new SavedSearch\Listing();
        $conditionParts = [];
        $conditionParams = [];

        //filter for current user
        $conditionParts[] = "(ownerId = ? OR sharedUserIds LIKE ?)";
        $conditionParams[] = $user->getId();
        $conditionParams[] = "%," . $user->getId() . ",%";

        //filter for query
        if (!empty($query)) {
            $conditionParts[] = "(name LIKE ? OR description LIKE ? OR category LIKE ?)";
            $conditionParams[] = "%" . $query . "%";
            $conditionParams[] = "%" . $query . "%";
            $conditionParams[] = "%" . $query . "%";
        }

        if (count($conditionParts) > 0) {
            $condition = implode(" AND ", $conditionParts);
            $searcherList->setCondition($condition, $conditionParams);
        }


        $searcherList->setOffset($offset);
        $searcherList->setLimit($limit);

        $sortingSettings = \Pimcore\Admin\Helper\QueryParams::extractSortingSettings(array_merge($request->request->all(), $request->query->all()));
        if ($sortingSettings['orderKey']) {
            $searcherList->setOrderKey($sortingSettings['orderKey']);
        }
        if ($sortingSettings['order']) {
            $searcherList->setOrder($sortingSettings['order']);
        }

        $results = []; //$searcherList->load();
        foreach($searcherList->load() as $result) {
            $results[] = [
                'id' => $result->getId(),
                'name' => $result->getName(),
                'description' => $result->getDescription(),
                'category' => $result->getCategory(),
                'owner' => $result->getOwner() ? $result->getOwner()->getUsername() . " (" . $result->getOwner()->getFirstname() . " " . $result->getOwner()->getLastName() . ")": "",
                'ownerId' => $result->getOwnerId()
            ];
        }

        // only get the real total-count when the limit parameter is given otherwise use the default limit
        if ($request->get("limit")) {
            $totalMatches = $searcherList->getTotalCount();
        } else {
            $totalMatches = count($results);
        }

        $this->json(array("data" => $results, "success" => true, "total" => $totalMatches));

    }

    /**
     * @param Request $request
     * @Route("/load-search")
     */
    public function loadSearchAction(Request $request) {

        $id = intval($request->get("id"));
        $savedSearch = SavedSearch::getById($id);
        if($savedSearch) {
            $config = json_decode($savedSearch->getConfig(), true);
            $this->json([
                'id' => $savedSearch->getId(),
                'classId' => $config['classId'],
                'settings' => [
                    'name' => $savedSearch->getName(),
                    'description' => $savedSearch->getDescription(),
                    'category' => $savedSearch->getCategory(),
                    'sharedUserIds' => $savedSearch->getSharedUserIds(),
                    'isOwner' => $savedSearch->getOwnerId() == $this->getUser()->getId(),
                    'hasShortCut' => $savedSearch->isInShortCutsForUser($this->getUser())
                ],
                'conditions' => $config['conditions'],
                'gridConfig' => $config['gridConfig']
            ]);
        }
    }

    /**
     * @param Request $request
     * @Route("/load-short-cuts")
     */
    public function loadShortCutsAction(Request $request) {

        $list = new SavedSearch\Listing();
        $list->setCondition("(ownerId = ? OR sharedUserIds LIKE ?) AND shortCutUserIds LIKE ?", [$this->getUser()->getId(), '%,' . $this->getUser()->getId() . ',%', '%,' . $this->getUser()->getId() . ',%']);
        $list->load();

        $entries = [];
        foreach($list->getSavedSearches() as $entry) {
            $entries[] = [
                "id" => $entry->getId(),
                "name" => $entry->getName()
            ];
        }

        $this->json(['entries' => $entries]);
    }

    /**
     * @param Request $request
     * @Route("/toggle-short-cut")
     */
    public function toggleShortCutAction(Request $request) {
        $id = intval($request->get("id"));
        $savedSearch = SavedSearch::getById($id);
        if($savedSearch) {

            $user = $this->getUser();
            if($savedSearch->isInShortCutsForUser($user)) {
                $savedSearch->removeShortCutForUser($user);
            } else {
                $savedSearch->addShortCutForUser($user);
            }
            $savedSearch->save();
            $this->json(['success' => 'true', 'hasShortCut' => $savedSearch->isInShortCutsForUser($user)]);

        } else {
            $this->json(['success' => 'false']);
        }
    }

    /**
     * @param Request $request
     * @Route("/get-users")
     */
    public function getUsersAction(Request $request) {

        $users = [];

        // condition for users with groups having DAM permission
        $condition = [];
        $rolesList = new \Pimcore\Model\User\Role\Listing();
        $rolesList->addConditionParam("CONCAT(',', permissions, ',') LIKE ?", '%,plugin_es_search,%');
        $rolesList->load();
        $roles = $rolesList->getRoles();

        foreach($roles as $role) {
            $condition[] = "CONCAT(',', roles, ',') LIKE '%," . $role->getId() . ",%'";
        }

        // get available user
        $list = new \Pimcore\Model\User\Listing();

        $condition[] = "admin = 1";
        $list->addConditionParam("((CONCAT(',', permissions, ',') LIKE ? ) OR " . implode(" OR ", $condition) . ")", '%,plugin_es_search,%');
        $list->addConditionParam('id != ?', $this->getUser()->getId());
        $list->load();
        $userList = $list->getUsers();

        foreach($userList as $user) {
            $users[] = [
                'id' => $user->getId(),
                'label' => $user->getUsername()
            ];
        }

        $this->json(['success' => true, 'total' => count($users), 'data' => $users]);
    }


    /**
     * @param Request $request
     * @Route("/check-index-status")
     */
    public function checkIndexStatusAction(Request $request)
    {

        $service = new Service();
        $this->json(['indexUptodate' => $service->updateQueueEmpty()]);

    }

}

