<?php

class SQLOperations
{
    public function __construct($userIDs, $type = ['all' => true])
    {
        $this->userIDs = $userIDs;
        $this->mySQL   = new MySqlDB();
        $this->type    = $type;
    }

    public function prepareModules()
    {

        if (($this->type['all'] ?? false) || ($this->type[TYPE_TOPIC] ?? false)) {
            $this->createModuleTable();
            $this->insertTopicData();
            $this->insertConceptData();
        }
        if (($this->type['all'] ?? false) || ($this->type[TYPE_TIMEDTEST] ?? false)) {
            $this->insertTimedTestData();
        }
        if (($this->type['all'] ?? false) || ($this->type['activity'] ?? false)) {
            $this->createActivityTable();
            $this->insertActivityData();
        }
    }

    public function prepareAttemptData()
    {

        if (($this->type['all'] ?? false) || ($this->type[TYPE_TOPIC] ?? false)) {
            $this->createQuestionTable();
            $this->insertNormalQuestion();
            $this->insertChallengeQuestion();
        }
        if (($this->type['all'] ?? false) || ($this->type[TYPE_TIMEDTEST] ?? false)) {
            $this->insertTimedTestQuestion();
        }

        if (($this->type['all'] ?? false) || ($this->type['topic'] ?? false) || ($this->type['timedTest'] ?? false)) {
            $this->updateHintInfo();
            $this->updateDynamicInfo();
            $this->updateHomeLoginInfo();
            $this->updateExtaInfo();
        }
    }

    public function getModuleData($userIDs)
    {
        $modules = "SELECT * FROM `mapping`.`user_module_progress` WHERE userID IN (" . implode(',', $userIDs) . ")";
        $result  = $this->mySQL->rawQuery($modules);
        return $result['data'] ?? [];
    }

    public function getAttemptData($userIDs)
    {
        $questons = "SELECT * FROM `mapping`.`userAttempt` WHERE userID IN (" . implode(',', $userIDs) . ") ORDER BY ttAttemptID, updationDateTime";
        $result   = $this->mySQL->rawQuery($questons);
        return $result['data'] ?? [];
    }

    private function createModuleTable()
    {
        # create table query
        $sql = "CREATE TABLE IF NOT EXISTS `mapping`.`user_module_progress` (
                  `srNo` int(11) AUTO_INCREMENT,
                  `userId` varchar(50) DEFAULT NULL,
                  `contentId` varchar(50) DEFAULT NULL,
                  `qcode` varchar(50) DEFAULT NULL,
                  `contentAttemptNumber` int(11) NOT NULL DEFAULT 0 ,
                  `contentAttemptId` varchar(50) DEFAULT NULL ,
                  `contentType` varchar(50) DEFAULT NULL,
                  `contentLanguageCode` varchar(50) NOT NULL DEFAULT 'en',
                  `revisionNumber` int(11) NOT NULL DEFAULT '1',

                  `mode` varchar(50) DEFAULT NULL,
                  `status` varchar(50) DEFAULT NULL,
                  `result` varchar(10) DEFAULT NULL,
                  `progress` int(10) DEFAULT NULL,
                  `level` varchar(50) DEFAULT NULL,
                  `attemptType` varchar(50) DEFAULT NULL,
                  `timeSpent` int(10) DEFAULT 0,
                  `totalQuestions` int(10) DEFAULT 0,
                  `totalSubmodules` int(10) DEFAULT 0,
                  `totalCorrect` int(10) DEFAULT 0,
                  `submodulesCompleted` int(10) DEFAULT 0,

                  `startSessionID` varchar(45) DEFAULT NULL,
                  `endSessionID` varchar(45) DEFAULT NULL,
                  `startTime` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
                  `endTime` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',

                  `clusterCode` varchar(45) DEFAULT NULL,
                  `clusterAttemptNo` int(11)  NULL,
                  `clusterAttemptID` varchar(45) DEFAULT NULL,
                  `conceptId` varchar(50) DEFAULT NULL,
                  `conceptVersion` int(11) NOT NULL DEFAULT '1',
                  `conceptContext` varchar(50) NOT NULL DEFAULT 'en',

                  `teacherTopicCode` varchar(50) DEFAULT NULL,
                  `ttAttemptNo` int(11) NOT NULL DEFAULT '0',
                  `ttAttemptID` varchar(45) DEFAULT NULL,
                  `topicId` varchar(50) DEFAULT NULL,
                  `topicVersion` int(11) NOT NULL DEFAULT '1',
                  `topicContext` varchar(50) NOT NULL DEFAULT 'en',

                  `learnParentInfo` varchar(50) DEFAULT NULL,
                  `otherInfo` varchar(50) DEFAULT NULL,
                  `subject` varchar(50) DEFAULT 'math',
                  `class` int(11) DEFAULT NULL,
                  `groupId` varchar(50) DEFAULT NULL,
                  `oldResult` varchar(50) DEFAULT NULL,
                  `classLevelResult`  varchar(50) DEFAULT NULL,
                  `flow` varchar(50) DEFAULT NULL,
                  `tSrNo` int(11) DEFAULT NULL,
                  PRIMARY KEY (`srNo`),
                  UNIQUE KEY `contentAttemptId` (`contentAttemptId`),
                  UNIQUE KEY `srNo_UNIQUE` (`srNo`)
                  )";
        $result = $this->mySQL->rawQuery($sql);
    }

    private function insertTopicData()
    {

        $topicPort = "INSERT INTO `mapping`.`user_module_progress`
              (
              `userId`,
              `contentId`,`qcode`,`contentAttemptNumber`,`contentAttemptId`,`contentType`,`revisionNumber`,`contentLanguageCode`,
              `totalQuestions`,`totalCorrect`,
              `level`,`mode`,`status`,
              `startTime`,`endTime`,
              `oldResult`, `classLevelResult`,`flow`
              )
              SELECT
                tt.userID,
                cd.newID as contentId, tt.teacherTopicCode, tt.ttAttemptNo, CONCAT('".TYPE_TOPIC."' ,'_',tt.ttAttemptID), '".TYPE_TOPIC."',cd.newRevisionNo, cd.newContext,
                tt.noOfQuesAttempted, round((tt.perCorrect*tt.noOfQuesAttempted)/100 ) as totalQues ,
                'regular','Learn','inprogress',
                tt.lastModified, tt.lastModified,
                tt.result, tt.classLevelResult, tt.flow
              FROM
                (SELECT * FROM educatio_adepts.adepts_teachertopicstatus
                          WHERE userID IN (" . implode(',', $this->userIDs) . ")
                          ORDER BY userID )  as tt
                left join mapping.common_mapping as cd on cd.contentType = '".TYPE_TOPIC."' AND cd.oldID = tt.teacherTopicCode
              order by tt.lastModified";
        $result = $this->mySQL->rawQuery($topicPort);
    }

    private function insertConceptData()
    {
        $conceptPort = "INSERT INTO `mapping`.`user_module_progress`
                (
                `userId`,
                `contentId`,`qcode`,`contentAttemptNumber`,`contentAttemptId`,`contentType`,`revisionNumber`,`contentLanguageCode`,
                `totalQuestions`,`totalCorrect`,
                `mode`,`result`,`attemptType`,`level`,`status`,
                `teacherTopicCode`,`ttAttemptNo`,`ttAttemptID`,`topicId`,`topicVersion`,`topicContext`,
                `startSessionID`,`endSessionID`,`startTime`,`endTime`,
                `oldResult`
                )
                SELECT
                  tt.userID,
                  cd.newID as contentId, tt.clusterCode, tt.clusterAttemptNo,  CONCAT('".TYPE_CONCEPT."' ,'_',tt.clusterAttemptID), '".TYPE_CONCEPT."',cd.newRevisionNo, cd.newContext,
                  tt.noOfQuesAttempted, round((tt.perCorrect*tt.noOfQuesAttempted)/100 )as totalQues ,
                  'Learn', IF(tt.result = 'SUCCESS' , 'pass', IF(tt.result = 'FAILURE', 'fail',NULL)),  IF(tt.attemptType = 'N' , 'regular', IF(tt.attemptType='R', 'remedial',NULL)), 'regular', 'inprogress',
                  td.teacherTopicCode, td.ttAttemptNo, CONCAT('".TYPE_TOPIC."' ,'_',tt.ttAttemptID), topic.newID, topic.newRevisionNo, topic.newContext,
                  tt.startSessionID,tt.endSessionID, tt.lastModified, tt.lastModified, tt.result
                FROM
                    (SELECT * FROM educatio_adepts.adepts_teachertopicclusterstatus
                            WHERE userID IN (" . implode(',', $this->userIDs) . ")
                            ORDER BY userID )  as tt
                  left join mapping.common_mapping as cd on cd.oldID = tt.clusterCode AND cd.contentType = '".TYPE_CONCEPT."'
                  left join educatio_adepts.adepts_teachertopicstatus as td on td.ttAttemptID = tt.ttAttemptID
                  left join mapping.common_mapping as topic on topic.oldID = td.teacherTopicCode AND topic.contentType = '".TYPE_TOPIC."'
                order by td.ttAttemptNo, tt.lastModified";
        $result = $this->mySQL->rawQuery($conceptPort);
    }

    private function insertTimedTestData()
    {
        $timedTest = "INSERT INTO `mapping`.`user_module_progress`
                (
                `userId`,
                `contentId`,`qcode`,`contentAttemptId`,`contentType`,`revisionNumber`,`contentLanguageCode`,
                `totalQuestions`,`totalCorrect`,`timeSpent`,
                `mode`,`progress`,`status`,`result`,
                `startSessionID`,`endSessionID`,`endTime`
                )
                SELECT
                  tt.userID,
                  cd.newID as contentId, tt.timedTestCode, CONCAT('".TYPE_TIMEDTEST."' ,'_',tt.timedTestID) , 'group',cd.newRevisionNo, cd.newContext,
                  tt.noOfQuesAttempted, tt.quesCorrect, tt.timeTaken,
                  '".TYPE_TIMEDTEST."', 100,'completed','pass',
                  tt.sessionID,tt.sessionID,tt.lastModified
                FROM
                  (SELECT * FROM educatio_adepts.adepts_timedtestdetails
                      WHERE userID IN (" . implode(',', $this->userIDs) . ")
                      ORDER BY userID )  as tt
                  left join mapping.common_mapping as cd on cd.oldID = tt.timedTestCode AND cd.contentType = 'group'
                order by tt.lastModified";
        $result = $this->mySQL->rawQuery($timedTest);
    }

    private function createQuestionTable()
    {
        #create a table for all question entry
        $createTable = "CREATE TABLE IF NOT EXISTS `mapping`.`userAttempt` (

          `newAttemptId` int(11) NOT NULL AUTO_INCREMENT,`oldAttemptID` varchar(45) DEFAULT NULL,
          `contentAttemptId` varchar(45) DEFAULT NULL,`userId` varchar(50) NOT NULL DEFAULT '00',`srNo` int(11) DEFAULT NULL,`sessionId` varchar(50) NOT NULL DEFAULT '00',

          `question` TEXT DEFAULT NULL, `qcode` varchar(45) DEFAULT NULL, `contentId` varchar(50) NOT NULL DEFAULT '00',`revisionNo` int(11) NOT NULL DEFAULT '1',`contentLanguageCode` varchar(50) NOT NULL DEFAULT 'en',`questionType` varchar(50) DEFAULT NULL,

          `userAnswer` varchar(50) DEFAULT NULL,`timeTaken` int(11) NOT NULL DEFAULT '0',`explanationTime` int(11) NOT NULL DEFAULT '0',`score` int(11) NOT NULL DEFAULT '0',`result` varchar(50) DEFAULT  NULL,

          `updationDateTime` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',

          `sdlNo` varchar(50) NOT NULL DEFAULT '00',`sdlAttemptNo` int(11) NOT NULL DEFAULT '0',`sdlAttemptId` varchar(50)  DEFAULT NULL,`sdlId` varchar(50)  DEFAULT NULL,`sdlVersion` int(11) NOT NULL DEFAULT '1',`sdlContext` varchar(50) NOT NULL DEFAULT 'en', `sdlType` varchar(50) NOT NULL DEFAULT '".TYPE_SDL."',

          `clusterCode` varchar(45) DEFAULT NULL,`clusterAttemptNo` int(11) NOT NULL DEFAULT '0',`clusterAttemptID` varchar(45) DEFAULT NULL, `conceptId` varchar(50) DEFAULT NULL,`conceptVersion` int(11) NOT NULL DEFAULT '1',`conceptContext` varchar(50) NOT NULL DEFAULT 'en',

          `teacherTopicCode` varchar(50) DEFAULT NULL,`ttAttemptNo` int(11) NOT NULL DEFAULT '0',`ttAttemptID` varchar(45) DEFAULT NULL,`topicId` varchar(50) DEFAULT NULL,`topicVersion` int(11) NOT NULL DEFAULT '1',`topicContext` varchar(50) NOT NULL DEFAULT 'en',

          `trials` int(10) DEFAULT NULL,
          `dynamic` int(10) DEFAULT NULL,
          `hint` int(10) DEFAULT NULL,

          `trialInfo` varchar(50) DEFAULT NULL,
          `dynamicParams` varchar(50) DEFAULT NULL,
          `hintUsed` tinyint(1) DEFAULT NULL,
          `equiEditor` varchar(50) DEFAULT NULL,
          `equiImage` varchar(50) DEFAULT NULL,
          `longResponse` varchar(200) DEFAULT NULL,
          `isSchoolLogin` tinyint(1) NOT NULL DEFAULT '1',
          `contentType` varchar(50) DEFAULT 'question',
          `mode` varchar(50) DEFAULT 'Learn',
          `creationDateTime` timestamp NULL DEFAULT NULL,
          `activityType` varchar(50) DEFAULT NULL,
          `subject` varchar(10) DEFAULT 'math',
          `extraParams` varchar(100) DEFAULT NULL,

          PRIMARY KEY (`contentAttemptId`),
          UNIQUE KEY `newAttemptId_UNIQUE` (`newAttemptId`),
          UNIQUE KEY `contentAttemptId_srNo_UNIQUE` (`contentAttemptId`),
          UNIQUE KEY `oldAttemptID_UNIQUE` (`oldAttemptID`)
                    )";
        $result = $this->mySQL->rawQuery($createTable);
    }

    private function insertNormalQuestion()
    {
        for ($i = 1; $i <= 10; $i++) {

            $selectQuestion = "INSERT INTO `mapping`.`userAttempt`
                (
                `oldAttemptID`,`contentAttemptId`,`userId`,`srNo`,`sessionId`,
                `question`,`qcode`,`contentId`,`revisionNo`,`contentLanguageCode`,`questionType`,
                `userAnswer`,`timeTaken`,`explanationTime`,`result`,`updationDateTime`,
                `sdlNo`,`sdlId`,`sdlVersion`,`sdlContext`,
                `clusterCode`,`clusterAttemptNo`,`clusterAttemptID`,`conceptId`,`conceptVersion`,`conceptContext`,
                `teacherTopicCode`,`ttAttemptNo`,`ttAttemptID`,`topicId`,`topicVersion`,`topicContext`,
                `trials`,`dynamic`,`hint`
                  )
                SELECT
                  tt.srNo, CONCAT(tt.srno, '_', " . $i . "), tt.userID, tt.questionNo, tt.sessionID,
                  q.question,tt.qcode, cd.newID as contentId,  cd.newRevisionNo as qVersion,cd.newContext as qContext,q.question_type,
                  tt.A, tt.S, tt.timeTakenForExpln,  if(tt.R=1,'pass','fail') ,
                  tt.lastModified, q.subDifficultyLevel,
                  sdl.newID as sdlId, sdl.newRevisionNo as sdlVersion, sdl.newContext as sdlContext,
                  tt.clusterCode,  cluster.clusterAttemptNo, CONCAT('".TYPE_CONCEPT."' ,'_', tt.clusterAttemptID),   con.newID as conceptId, con.newRevisionNo as conVesrion, con.newContext as conContext,
                  tt.teacherTopicCode, topic.ttAttemptNo, CONCAT('".TYPE_TOPIC."' ,'_',cluster.ttAttemptID),  td.newID as topicId, td.newRevisionNo as topicVersion, td.newContext as topicContext,
                  q.trials, q.dynamic, q.hint
                FROM
                  (select * from  educatio_adepts.adepts_teachertopicquesattempt_class" . $i . " where userId  IN (" . implode(',', $this->userIDs) . ") order by sessionID) as tt
                  left join educatio_adepts.adepts_questions as q on q.qcode = tt.qcode
                  left join educatio_adepts.adepts_teachertopicclusterstatus as cluster on cluster.clusterAttemptID= tt.clusterAttemptID
                  left join mapping.common_mapping as cd on cd.oldID = tt.qcode and cd.contentType = 'question'
                  left join mapping.common_mapping as td on td.oldID = tt.teacherTopicCode and td.contentType = '".TYPE_TOPIC."'
                  left join mapping.common_mapping as con on con.oldID = tt.clusterCode and con.contentType = '".TYPE_CONCEPT."'
                  left join educatio_adepts.adepts_teachertopicstatus as topic on topic.ttAttemptID= cluster.ttAttemptID
                  left join mapping.common_mapping as sdl on sdl.oldID = CONCAT(tt.clusterCode, '_', REPLACE( q.subDifficultyLevel, '.', '_'))  and sdl.contentType = '".TYPE_SDL."'
                order by tt.lastModified";
            $result = $this->mySQL->rawQuery($selectQuestion);
        }

    }

    private function insertChallengeQuestion()
    {
        $challengeQuestion = "INSERT INTO `mapping`.`userAttempt`
                (
                `oldAttemptID`,`contentAttemptId`,`userId`,`srNo`,`sessionId`,
                `question`,`qcode`,`contentId`,`revisionNo`,`contentLanguageCode`,`questionType`,
                `userAnswer`,`timeTaken`,`score`,`updationDateTime`,
                `teacherTopicCode`,`ttAttemptNo`,`ttAttemptID`,`topicId`,`topicVersion`,`topicContext`,
                `trials`,`dynamic`,`hint`,`mode`
                  )
                SELECT
                  tt.srno,CONCAT(tt.srno, '_','c'), tt.userID, tt.questionNo, tt.sessionID,
                  q.question,tt.qcode, cd.newID as contentId,  cd.newRevisionNo as qVersion,cd.newContext as qContext,q.question_type,
                  tt.A, tt.S,   tt.R as score,
                  tt.lastModified,
                  topic.teacherTopicCode, topic.ttAttemptNo, CONCAT('".TYPE_TOPIC."' ,'_',tt.ttAttemptID ),
                  td.newID as topicId, td.newRevisionNo as topicVersion, td.newContext as topicContext,
                  q.trials, q.dynamic, q.hint, 'Challenge' as mode
                FROM
                  (select * from  educatio_adepts.adepts_ttChallengeQuesAttempt where userId IN (" . implode(',', $this->userIDs) . ") order by sessionID) as tt
                  left join educatio_adepts.adepts_questions as q on q.qcode = tt.qcode
                  left join mapping.common_mapping as cd on cd.oldID = tt.qcode and cd.contentType = 'question'
                  left join educatio_adepts.adepts_teachertopicstatus as topic on topic.ttAttemptID= tt.ttAttemptID
                  left join mapping.common_mapping as td on td.oldID = topic.teacherTopicCode and td.contentType = '".TYPE_TOPIC."'
                order by tt.lastModified";
        $result = $this->mySQL->rawQuery($challengeQuestion);
    }

    private function insertTimedTestQuestion()
    {
        $timedTestQuestion = "INSERT INTO `mapping`.`userAttempt`
                    (
                    `oldAttemptID`,`contentAttemptId`,`userId`,`srNo`,`sessionId`,
                    `question`,`qcode`,`contentId`,`revisionNo`,`contentLanguageCode`,`questionType`,
                    `userAnswer`,`timeTaken`,`result`,`updationDateTime`,`mode`,
                    `sdlNo`,`sdlAttemptNo`,`sdlAttemptId`,`sdlId`,`sdlVersion`,`sdlContext`,`sdlType`
                      )
                    SELECT
                        CONCAT(tt.timedTestID,'_',tt.qno),CONCAT('".TYPE_TIMEDTEST."',tt.timedTestID,'_',tt.qno),
                        tt.userID, tt.qno, td.sessionID,
                        tt.question, tt.question, cd.newID as contentId,  cd.newRevisionNo as qVersion,cd.newContext as qContext,q.question_type,
                        tt.userResponse, tt.S,  if(tt.result=1,'pass','fail') ,
                        tt.lastModified,'".TYPE_TIMEDTEST."',
                        tt.timedTestCode,1, CONCAT('".TYPE_TIMEDTEST."',tt.timedTestID), timedTest.newID as sdlID, timedTest.newRevisionNo as sdlVesrion, timedTest.newContext as sdlContext, 'group'
                        FROM
                        (select * from  educatio_adepts.adepts_timedtestquesattempt where userId IN (" . implode(',', $this->userIDs) . ")) as tt
                        left join educatio_adepts.adepts_questions as q on q.qcode = tt.question
                        left join educatio_adepts.adepts_timedtestDetails as td on td.timedTestID = tt.timedTestID
                            left join mapping.common_mapping as timedTest on timedTest.oldID = tt.timedTestCode and timedTest.contentType = 'group'
                        left join mapping.common_mapping as cd on q.qcode != NULL and cd.oldID = q.qcode and cd.contentType = 'question'
                      order by tt.lastModified";
        $result = $this->mySQL->rawQuery($timedTestQuestion);
    }

    private function updateHintInfo()
    {
        $updateHint = "UPDATE  `mapping`.`userAttempt` ,
              (select * from `educatio_adepts`.`adepts_hintused` where userID IN (" . implode(',', $this->userIDs) . ")) as hint
              SET mapping.userAttempt.hintUsed = hint.hintUsed
              WHERE mapping.userAttempt.oldAttemptID = hint.srno";
        $result = $this->mySQL->rawQuery($updateHint);
    }

    private function updateDynamicInfo()
    {
        $updateDynamics = "UPDATE  `mapping`.`userAttempt` ,
              (select * from `educatio_adepts`.`adepts_dynamicparameters` where userID IN (" . implode(',', $this->userIDs) . ")) as dynamic
              SET mapping.userAttempt.dynamicParams = dynamic.parameters
              WHERE mapping.userAttempt.oldAttemptID = dynamic.quesAttempt_srno";
        $result = $this->mySQL->rawQuery($updateDynamics);
    }

    private function updateHomeLoginInfo()
    {
        $updateSchoolLogin = "UPDATE `mapping`.`userAttempt`,
                      (select * from `educatio_adepts`.`adepts_homeschoolusage` where userID IN (" . implode(',', $this->userIDs) . ") and flag='home') as home
                      SET mapping.userAttempt.isSchoolLogin = 0
                      WHERE mapping.userAttempt.sessionId = home.sessionID";
        $result = $this->mySQL->rawQuery($updateSchoolLogin);
    }

    private function updateHomeLoginInfoActivity()
    {
        $updateSchoolLogin = "UPDATE `mapping`.`userActivity`,
                      (select * from `educatio_adepts`.`adepts_homeschoolusage` where userID IN (" . implode(',', $this->userIDs) . ") and flag='home') as home
                      SET mapping.userAttempt.isSchoolLogin = 0
                      WHERE mapping.userAttempt.sessionId = home.sessionID";
        $result = $this->mySQL->rawQuery($updateSchoolLogin);
    }

    private function updateExtaInfo()
    {
        #update equiEditor response
        $updateEqui = "UPDATE mapping.userAttempt,
              (select * from educatio_adepts.adepts_equationEditorResponse where userID IN (" . implode(',', $this->userIDs) . ") ) as equi
              SET mapping.userAttempt.equiEditor = equi.eeResponse and  mapping.userAttempt.equiImage = equi.eeResponseImg
              WHERE mapping.userAttempt.oldAttemptID = equi.srno";
        $result = $this->mySQL->rawQuery($updateEqui);
        #update longUserResponse
        $updateLong = "UPDATE mapping.userAttempt,
              (select * from educatio_adepts.longuserresponse where userID IN (" . implode(',', $this->userIDs) . ")) as longR
              SET  mapping.userAttempt.longResponse = longR.userResponse
              WHERE mapping.userAttempt.oldAttemptID = longR.srno;";
        $result = $this->mySQL->rawQuery($updateLong);
    }

    public function getTrialInfo($userIDs)
    {
      
        $trialInfoQuery = "SELECT quesAttempt_srno as contentAttemptId, srno, userResponse as userAnswer,trialNo FROM educatio_adepts.adepts_teacherTopicQuesTrialDetails
                       WHERE userID IN (" . implode(',', $userIDs) . ")";
        $result = $this->mySQL->rawQuery($trialInfoQuery);

    }

    private function insertActivityData()
    {

        # Activity(game/enrichment/introduction) Attempt in Attempts Table
        $queryToInsertAllActivities = "INSERT INTO `mapping`.`userActivity`
          (
            `oldAttemptID`,`userId`,`sessionId`,
            `oldActivityId`,`contentId`,`revisionNumber`,`contentLanguageCode`,`extraParams`,
            `timeTaken`,`result`,`activityTime`,`score`, `activityType`, 
            `status`, `attemptCount`
          )
          SELECT
            ac.srno, ac.userID, ac.sessionID,
            ac.gameID, cd.newID as contentId,  cd.newRevisionNo as revisionNumber,cd.newContext as contentLanguageCode, ac.extraParams,
            ac.timeTaken, if(ac.completed=1,'pass', 'null'), ac.lastModified, ac.score, gm.type, 
            if(ac.completed=1,'completed', if(ac.completed=0,'inComplete', 'skip')), ac.attemptCnt
            FROM
          (
                SELECT * from  educatio_adepts.adepts_userGameDetails
                WHERE userID IN (" . implode(',', $this->userIDs) . ") ORDER BY userID ) as ac
                left join mapping.common_mapping as cd on cd.oldID = ac.gameID and oldTableName = 'adepts_gamesMaster'
                left join educatio_adepts.adepts_gamesMaster as  gm on gm.gameID = ac.gameID
                order by ac.lastModified";

        $resultActivityAll = $this->mySQL->rawQuery($queryToInsertAllActivities);

        # Activity(remedial) Attempt in Attempts Table
        $queryToInsertRemdial = "INSERT INTO `mapping`.`userActivity`
          (
            `oldAttemptID`,`userId`,`sessionId`,
            `oldActivityId`,`contentId`,`revisionNumber`,`contentLanguageCode`,`extraParams`,
            `timeTaken`,`result`,`activityTime`,`score`, `activityType`, `status`
          )
          SELECT
            rem.remedialAttemptID, rem.userID, rem.sessionID,
            rem.remedialItemCode, cd.newID as contentId,  cd.newRevisionNo as revisionNumber,cd.newContext as contentLanguageCode, '',
            rem.timeTaken, if(rem.result=1,'pass',if(rem.result=2,'fail', 'null')) , rem.lastModified, 0, 'remedial', if(rem.result=1,'completed', (if(rem.result=2, 'completed','null')))
          FROM
          (
                select * from  educatio_adepts.adepts_remedialItemAttempts
                WHERE userID IN (" . implode(',', $this->userIDs) . ")
                ORDER BY userID ) as rem
                left join mapping.common_mapping as cd on cd.oldID = rem.remedialItemCode and oldTableName = 'adepts_remedialItemMaster'
                order by rem.lastModified";
        $resultActivityRem = $this->mySQL->rawQuery($queryToInsertRemdial);
    }

    public function getActivityLevelWiseInfo($userIDs)
    {

        # Activity(game/enrichment/introduction) Attempt in Attempts Table
        $queryToGetLeveWiseInfo = "
          SELECT
            ac.srno as contentAttemptId, ac.userID as userId,
            ac.level, ac.score, ac.timeTaken, ac.status
          FROM educatio_adepts.adepts_activityLevelDetails as ac where ac.userID IN (" . implode(',', $userIDs) . ")";

        $resultActivityLevelWise = $this->mySQL->rawQuery($queryToGetLeveWiseInfo);
        return $resultActivityLevelWise['data'] ?? [];

    }

    public function getActivityData($userIDs)
    {
        $activities = "SELECT * FROM `mapping`.`userActivity` WHERE userId IN (" . implode(',', $userIDs) . ")";
        $result     = $this->mySQL->rawQuery($activities);
        return $result['data'] ?? [];
    }

     private function createActivityTable()
    {

        #create a table for all question entry
        $createTable = "CREATE TABLE IF NOT EXISTS `mapping`.`userActivity` (

          `oldAttemptID` int(11) NOT NULL, 
          `userId` varchar(50) NOT NULL DEFAULT '00',
          `sessionId` varchar(50) NOT NULL DEFAULT '00',

          `oldActivityId` varchar(45) DEFAULT NULL, 
          `contentId` varchar(50) NOT NULL DEFAULT '00',
          `revisionNumber` int(11) NOT NULL DEFAULT 1,
          `contentLanguageCode` varchar(50) NOT NULL DEFAULT 'en',
          `extraParams` varchar(100) DEFAULT NULL,

          `timeTaken` int(11) NOT NULL DEFAULT 0,
          `score` int(11) NOT NULL DEFAULT 0,
          `result` varchar(50) DEFAULT  NULL,
          `activityType` varchar(20) DEFAULT NULL,
          `status` varchar(20) DEFAULT NULL,
          `activityTime` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
          `contentAttemptNumber` int(11) NOT NULL DEFAULT 1,
          `srNo` int(11) NOT NULL AUTO_INCREMENT,
          `mode` varchar(20) NOT NULL DEFAULT 'Activity',
          `subject` varchar(20) NOT NULL DEFAULT 'math',
          `isSchoolLogin` int(10) NOT NULL DEFAULT 1,
          `contentType` varchar(20) DEFAULT 'activity',
          `attemptCount` int(10) NOT NULL DEFAULT 1
               
          PRIMARY KEY (`srNo`),
          CONSTRAINT UC_User_Attempt UNIQUE (userId,oldActivityId,oldAttemptID)
        )";
        $result = $this->mySQL->rawQuery($createTable);
    }

}
