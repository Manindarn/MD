<?php
require_once TYPES_PATH . 'Question.php';
require_once TYPES_PATH . 'UserModule.php';
require_once TYPES_PATH . 'UserPedagogyState.php';
require_once TYPES_PATH . 'UserState.php';
require_once TYPES_PATH . 'SQLOperations.php';

class AttemptData
{

    private $userIDs       = [];
    private $portType      = null;
    private $SQLOperations = null;
    private $newUserData   = [];
    private $schoolFlow    = 'MS';

    private $userState     = [];
    private $topicInfo     = [];
    private $tempModule    = [];
    private $tempUPS       = [];
    private $tempQuestion  = [];
    private $modules       = [];
    private $questions     = [];
    private $topicIdsMap   = [];
    private $topicAttempts = [];
    private $activities    = [];

    private $esearch    = null;
    private $mongoQuery = null;

    public function __construct($newUserData, $flow, $portType = ['all' => true])
    {
        $this->userIDs       = array_keys($newUserData);
        $this->portType      = $portType;
        $this->SQLOperations = new SQLOperations($this->userIDs, $this->portType);
        $this->newUserData   = $newUserData;
        $this->schoolFlow    = $flow;

        // $this->esearch    = new ElasticSearchDB();
        $this->mongoQuery = new APICalls();
    }

    public function port()
    {
        // $this->SQLOperations->prepareModules();
        // $this->SQLOperations->prepareAttemptData();
        foreach ($this->newUserData as $oldID => $details) {
            $this->userState[$oldID] = new UserState($details);
            if (($this->portType['all'] ?? false) || ($this->portType['topic'] ?? false) || ($this->portType['timedTest'] ?? false)) {
                $this->portModuleData($oldID);
                $this->portQuestionData($oldID);
            }
            if (($this->portType['all'] ?? false) || ($this->portType['activity'] ?? false)) {
                $this->portActivityData($oldID);
            }

            $this->insertIntoReArch($oldID);

            $this->user[$oldID] = [
                // 'moduleAttempts' => $this->topicAttempts,
                'UPS' => $this->tempUPS,
                // 'US' => $this->userState[$oldID]->getUserState()
                // 'modules' =>$this->tempModule,
                // 'questions'=>$this->tempQuestion,
                // 'topicInfo' => $this->topicInfo,
            ];
            $this->tempModule    = [];
            $this->tempUPS       = [];
            $this->topicAttempts = [];
            $this->questions     = [];
            $this->activities    = [];
        }
        return $this->getStatus();
    }

    public function portModuleData($oldID)
    {
        $userIDs       = is_array($oldID) ? $oldID : [$oldID];
        $this->modules = $this->SQLOperations->getModuleData($userIDs);
        $this->getTopicInfoFromPedagogy();

        foreach ($this->modules as $k => $details) {
            $userData = $this->newUserData[$details['userId']];
            $details  = array_merge($details, $userData);

            if ($details['contentType'] == TYPE_TOPIC) {
                $this->CheckAndCreateUPS($details);
            }

            $this->updateSubModuleCount($details);
            $this->tempModule[$details['contentAttemptId']] = new UserModule($details);
        }

        $this->modules = null;
    }

    public function portQuestionData($oldID)
    {
        $userIDs         = is_array($oldID) ? $oldID : [$oldID];
        $this->questions = $this->SQLOperations->getAttemptData($userIDs);
        $trialInfoData   = $this->SQLOperations->getTrialInfo($userIDs);
        $sessionSrNo     = [];
        foreach ($this->questions as $key => &$v) {
            $userData = $this->newUserData[$v['userId']];
            $v        = array_merge($v, $userData);
            $this->setSerialNoAndPortSDL($sessionSrNo, $v, $key);
            $this->tempQuestion[$v['contentAttemptId']] = new Question($v);
            if (in_array($v['mode'], [LEARN, 'Challenge'])) {
                $this->tempUPS[$v['UPID'] . "_" . $v['topicId']]->updateDisplayNodes($v);
            }
            $this->topicAttempts[$v['ttAttemptID']][$v['clusterAttemptID']][$v['sdlAttemptId']][] = $v['contentAttemptId'];
            $this->checkAndUpdateTrailInfo($v, $trialInfoData);
            if ($v['mode'] == 'Challenge') {
                $this->userState[$v['userId']]->updateChallengeQuestionAttempt($details);
            }
        }
    }

    public function insertIntoReArch($oldID){
        # write queries to insert into elasticsearch and MongoDB
        // to MongoDB
        # this->tempUPS data
        # this->userState data
        
        // to Elastic Search
        # this->tempModule data
        # this->tempQuestion data
        # this->tempActivity data
    }

    public function CheckAndCreateUPS(&$details)
    {
        # generic for any pedagoogyType

        $this->userState[$details['userId']]->updateModuleInfo($details);
        $details['upsID']          = $upsID          = $details['UPID'] . "_" . $details['contentId'];
        if($details['contentType'] == TYPE_TOPIC){
            $concepts                  = $this->getConcepts($details);
            $details['conceptList']    = $concepts['class'] ?? [];
            $details['allConceptList'] = $concepts['all'] ?? [];
            $this->topicAttempts[$details['contentAttemptId']] = [];
        }
        if (!isset($this->tempUPS[$upsID])) {
            $this->tempUPS[$upsID] = new UserPedagogyState($details);
        } else {
            $this->tempUPS[$upsID]->updateTopicAttemptInfo($details);
        }
    }

    public function updateUPS($details,$challenge)
    {
        $upsID = $details['UPID'] . "_" . $details['topicId'];
        if (isset($this->tempUPS[$upsID])) {
            $this->tempUPS[$upsID]->updateConceptDeatils($details,$challenge);
        }

        $this->topicAttempts[$details['ttAttemptID']][$details['contentAttemptId']] = [];
    }

    public function updateSubModuleCount(&$details)
    {
        if ($details['contentType'] == TYPE_TOPIC) {

            $details['totalSubmodules'] = count($details['conceptList']);

        } else if ($details['contentType'] == TYPE_CONCEPT) {
            $topicID                    = $details['topicId'] . "_" . $details['topicVersion'];
            $details['totalSubmodules'] = count($this->topicInfo[$topicID]['conceptDetails'][$details['contentId']]['contentNodes']['LearningNodes'] ?? []);
            #update challenge pool in US
            $challengeQs = $this->topicInfo[$topicID]['conceptDetails'][$details['contentId']]['contentNodes']['ChallengeNodes'] ?? [];
            if (!empty($challengeQs)) {
                $this->userState[$details['userId']]->updateChallengePool($challengeQs);
                $this->updateUPS($details,$challengeQs);
            }
        }
    }

    public function getTopicInfoFromPedagogy()
    {
        $topicIds = [];
        foreach ($this->modules as $k => $v) {
            if ($v['contentType'] == TYPE_TOPIC) {
                $topicVersion = $v['contentId'] . "_" . $v['revisionNumber'];
                if (!in_array($topicVersion, array_keys($this->topicInfo))) {
                    $topicIds[]                       = $topicVersion;
                    $this->topicIdsMap[$topicVersion] = $v['qcode'];
                }
            }
        }

        $this->topicInfo = $this->mongoQuery->getTopicInfo(array_values(array_unique($topicIds)));
    }

    public function getConcepts($details)
    {
        $returnData = ['all' => [], 'class' => []];
        $topicID    = $details['contentId'] . "_" . $details['revisionNumber'];

        if (isset($this->topicInfo[$topicID])) {
            $concepts = $this->topicInfo[$topicID]['conceptDetails'];
            $flow     = isset($details['flow']) && in_array($details['flow'], ['MS', 'CBSE', 'ICSE', 'IGCSE', 'HIN', 'GUJ']) ? $details['flow'] : $details['defaultFlow'];
            # logic pending for custom topics
            $class           = $details['class'];
            $conceptFunction = function ($each) use ($flow, $class, &$returnData) {
                if ($each['latest']) {
                    $p = $each['pedagogyDetails']['flow'];
                    if (isset($p[$flow])) {
                        $returnData['all'][] = [
                            "ID"      => $each['basePedagogyID'],
                            "PSID"    => $each['_id'],
                            "version" => $each['version'],
                            'type'    => $each['type'],
                            'classes' => $p[$flow]['levels'],
                        ];
                        if (in_array($class, $p[$flow]['levels'])) {
                            $hasActivity = false;
                            if ($each['contentNodes']['ActivityNodes'] ?? false && !empty($each['contentNodes']['ActivityNodes'])) {
                                $hasActivity  = true;
                                $activityList = $each['contentNodes']['ActivityNodes'];
                            }
                            $temp = [
                                "ID"          => $each['basePedagogyID'],
                                "PSID"        => $each['_id'],
                                "version"     => $each['version'],
                                "hasActivity" => $hasActivity,
                            ];
                            if ($hasActivity) {
                                $temp['activityList'] = $activityList;
                            }
                            $returnData['class'][] = $temp;
                        }
                    }
                }
            };
            array_map($conceptFunction, $concepts);
            $this->topicInfo[$topicID]['classWise'][$details['class']] = $returnData['class'];
        }
        return $returnData;
    }

    public function checkAndUpdateTrailInfo($v, $trialInfoData)
    {
        # update trial info
        if ($v['trials'] > 1) {
            $data = null;
            foreach ($trialInfoData as $k => $trials) {
                if ($trials['contentAttemptId'] == $v['contentAttemptId']) {
                    $data[] = $trials;
                }
            }
            if (!is_null($data)) {
                $this->tempQuestion[$v['contentAttemptId']]->updateTrialInfo($data);
            }
        }
    }

    public function setSerialNoAndPortSDL(&$sessionSrNo, &$v, $key)
    {
        if (!isset($sessionSrNo[$v['sessionId']])) {
            # sessionWise serial no assigning
            $sessionSrNo[$v['sessionId']] = 0;
        }
        if ($v['sdlType'] != 'group') {
            $this->portSDL($v, $key);
            $v['srNo'] = ++$sessionSrNo[$v['sessionId']];
        } else {
            # for timedTest collectionInfo
            $flag = $v['srNo'] == 1 ? 'start' : ($this->questions[$key + 1]['sdlId'] != $v['sdlId'] ? 'end' : null);
            if ($flag == 'start') {
                ++$sessionSrNo[$v['sessionId']];
            }
            $v['collectionInfo'] = [
                'flag'      => $flag,
                'type'      => 'group',
                'contentId' => $v['sdlId'],
                'srNo'      => $sessionSrNo[$v['sessionId']],
            ];
        }
    }

    public function prepareDataForSDL($details)
    {
        $mapping = [
            'userId'              => 'userId', 'groupId'      => 'groupId', 'contentType'           => 'sdlType', 'contentId' => 'sdlId', 'contentAttemptId' => 'sdlAttemptId', 'contentAttemptNumber' => 'sdlAttemptNo', 'class'             => 'class', 'UPID'             => 'UPID', 'totalCorrect'       => 0, 'mode'                     => 'mode',
            'submodulesCompleted' => 1, 'level'               => 'level',
            'startSessionID'      => 'sessionId', 'startTime' => 'updationDateTime', 'endSessionID' => 'sessionId', 'endTime' => 'updationDateTime', 'orgID' => 'orgID', 'contentLanguageCode'         => 'contentLanguageCode', 'revisionNo' => 'revisionNo', 'ttAttemptID' => 'ttAttemptID', 'ttAttemptNo' => 'ttAttemptNo', 'topicVersion' => 'topicVersion', 'topicContext' => 'topicContext', 'topicId' => 'topicId', 'conceptId' => 'conceptId', 'clusterAttemptID' => 'clusterAttemptID', 'clusterAttemptNo' => 'clusterAttemptNo', 'conceptVersion' => 'conceptVersion', 'conceptContext' => 'conceptContext',
        ];

        foreach ($mapping as $k => $v) {
            $returnData[$k] = $details[$v] ?? $v;
        }
        if ($details['result'] == PASS) {
            $returnData['result'] = PASS;
            $returnData['status'] = COMPLETED;
        }
        return $returnData;
    }
    public function portSDl(&$details, $key)
    {
        # create sdlAttemptId
        if ($key > 1 && $this->questions[intval($key) - 1]['sdlId'] == $details['sdlId']) {
            $details['sdlAttemptId'] = $this->questions[intval($key) - 1]['sdlAttemptId'];
            $details['sdlAttemptNo'] = $this->questions[intval($key) - 1]['sdlAttemptNo'];
            if ($this->tempModule[$details['sdlAttemptId']]->updateSubmoduleCount($details, true, true)) {
                if ($this->tempModule[$details['clusterAttemptID']]->updateSubmoduleCount($details, true)) {
                    $this->tempModule[$details['ttAttemptID']]->updateSubmoduleCount($details, true);
                }

            }
        } else {
            $details['sdlAttemptId'] = $this->generateHashCode($details['sdlId']);
            $sdls                    = $this->topicInfo[$details['topicId'] . "_" . $details['topicVersion']]['conceptDetails'][$details['conceptId']]['contentNodes']['LearningNodes'];
            if (true) {
                $details['sdlAttemptNo'] = 1; //logic check from pedagogyData
                $details['level']        = REGULAR;
            }
            $this->tempModule[$details['sdlAttemptId']]                                                           = new UserModule($this->prepareDataForSDL($details));
            $this->topicAttempts[$details['ttAttemptID']][$details['clusterAttemptID']][$details['sdlAttemptId']] = [];
            if ($details['result'] == PASS) {
                if ($this->tempModule[$details['clusterAttemptID']]->updateSubmoduleCount($details, true)) {
                    $this->tempModule[$details['ttAttemptID']]->updateSubmoduleCount($details, true);
                }
            }
            $upsID = $details['UPID'] . "_" . $details['topicId'];
            if (isset($this->tempUPS[$upsID])) {
                $this->tempUPS[$upsID]->updateSDLDetails();
            }
        }
    }

    public function getStatus()
    {
        $returnData = [
            'topics' => array_keys($this->topicInfo),
            'users'  => $this->user,
        ];
        $this->topicInfo   = null;
        $this->topicIdsMap = null;
        $this->userState   = null;
        return $returnData;
    }

    public function generateHashCode($string)
    {
        return md5($string . '-' . microtime());
    }

    public function portActivityData($oldID)
    {
        $userIDs                = is_array($oldID) ? $oldID : [$oldID];
        $this->activities       = $this->SQLOperations->getActivityData($userIDs);
        $levelWiseInfo          = $this->SQLOperations->getActivityLevelWiseInfo($userIDs);
        $levelDataAttemptIdWise = [];

        #Format levelWise
        if (is_array($levelWiseInfo) and isset($levelWiseInfo)) {

            foreach ($levelWiseInfo as $k => $levelDetails) {
                $level = 'L' . $levelDetails['level'];
                $levelDataAttemptIdWise[$levelDetails['contentAttemptId']][$level] = [
                    'timeTaken' => $levelDetails['timeTaken'],
                    'score'     => $levelDetails['score'],
                    'level'     => $level,
                    'status'    => $levelDetails['status'],
                ];
            }
        }

        $activityDataForActivityTable = $activityDataForAttemptsTable = [];

        foreach ($this->activities as $k => $activityDetails) {
            $userData         = $this->newUserData[$activityDetails['userId']];
            $activityDetails  = array_merge($activityDetails, $userData);
            //$activityDetails['newContentAttemptId'] = $this->generateHashCode($activityDetails['oldAttemptID']);
            $activityDetails['newContentAttemptId'] = $activityDetails['activityType'].'_'.$activityDetails['oldAttemptID'];
            
            if(isset($levelDataAttemptIdWise[ $activityDetails['oldAttemptID'] ])){
                $activityDetails['levelWiseData'] = $levelDataAttemptIdWise[$activityDetails['oldAttemptID']];
                $activityDetails['numberOfLevelsCompleted'] = $this->levelsCompleted( $levelDataAttemptIdWise[$activityDetails['oldAttemptID']] );
            }
           
            //print_r(json_encode($activityDetails));
            #create UPS
            $this->userState[$details['userId']]->updateModuleInfo($activityDetails);
            
            $upsID          = $activityDetails['UPID'] . "_" . $activityDetails['contentId'];
            $this->temp[$upsID] = (new UserPedagogyState($activityDetails))->getData();
            
            #Prepare data for ES Activity
            
            $activityDataForActivityTable[] = $this->prepareForActivityTableFormat($activityDetails);
            $activityDataForAttemptsTable[] = $this->prepareForAttemptsTableFormat($activityDetails);   

            
        }
        //  die;



        #Prepare for bulk Insert
        // $bulkForAttempt  = $this->esearch->formatToElasticBulk($this->insertData, 'attempt', 'contentAttemptId');
        // $bulkForActivity = $this->esearch->formatToElasticBulk($this->insertData, 'activity', 'contentAttemptId');

        #Insert data
        // $resultAttempt  = $this->esearch->bulk('attempt', $bulkForAttempt);
        // $resultActivity = $this->esearch->bulk('activity', $bulkForActivity);
    }

    public function prepareForAttemptsTableFormat($activityData)
    {
        $relatedInfo = [];
        $relatedInfo['levelWiseData'] = $activityData['levelWiseData'] ?? null;
        $relatedInfo['noOfLevelsCompleted'] = $activityData['numberOfLevelsCompleted'] ?? null;
        $relatedInfo['extraParams'] = $activityData['extraParams'] ?? null;

        $temp = [
            'isSchoolLogin'        => true,
            'userId'               => $activityData['UPID'],
            'groupId'              => $activityData['groupId'],
            'class'                => $activityData['class'],
            'subject'              => 'math',
            'updationDateTime'     => $activityData['activityTime'],
            'creationDateTime'     => $activityData['activityTime'],
            'sessionId'            => $activityData['sessionId'],
            'contentType'          => 'activity',
            'activityType'         => $activityData['activityType'],
            'contentId'            => $activityData['contentId'],
            'contentAttemptNumber' => 1,
            'contentAttemptId'     => $activityData['newContentAttemptId'],
            'contentLanguageCode'  => $activityData['contentLanguageCode'],
            'revisionNo'           => $activityData['revisionNumber'],
            'score'                => $activityData['score'],
            'timeTaken'            => $activityData['timeTaken'],
            'status'               => $activityData['status'],
            'result'               => $activityData['result'],
            'mode'                 => LEARN,
            'relatedInfo'          => $relatedInfo,
        ];
        return $temp;
    }

    public function prepareForActivityTableFormat($activityData)
    { 
        return  [
            'userId'                  => $activityData['UPID'],
            'groupId'                 => $activityData['groupId'],
            'class'                   => $activityData['class'],
            'subject'                 => 'math',
            'activityTime'            => $activityData['activityTime'],
            'sessionId'               => $activityData['sessionId'],
            'activityType'            => $activityData['activityType'],
            'contentId'               => $activityData['contentId'],
            'contentAttemptNumber'    => 1,
            'contentAttemptId'        => $activityData['newContentAttemptId'],
            'srNo'                    => $activityData['srNo'],
            'contentLanguageCode'     => $activityData['contentLanguageCode'],
            'revisionNo'              => $activityData['revisionNumber'],
            'score'                   => $activityData['score'],
            'timeTaken'               => $activityData['timeTaken'],
            'status'                  => $activityData['status'],
            'result'                  => $activityData['result'],
            'extraParams'             => $activityData['extraParams'],
            'otherInfo'               => null,
            'isChallenge'             => false,
            'levelWiseInfo'           => $activityData['levelWiseData'] ?? null,
            'numberOfLevelsCompleted' => $activityData['numberOfLevelsCompleted'] ?? null,

        ];
        
    }

    public function levelsCompleted($levelWiseData){
        $levelsCompleted = 0;
        if(isset($levelWiseData) AND is_array($levelWiseData)){
            $levelsCompleted =
                count(array_filter($levelWiseData, function ($l) {
                if ($l['status'] == 1) {
                    return true;
                }
            }));
                
        }
        return $levelsCompleted;    
    }

}
