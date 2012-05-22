<?php

class App_Service_DocumentService {
    private $_db;
    
    function __construct(){
        $this->_db = Zend_Db_Table::getDefaultAdapter();
    }
    
    /******* PUBLIC GET QUERIES *******/
    
    /***
     * Get list of all the documents
     */
    public function getDocuments()
    {
        $select = $this->_db->select()->from('documents');
        
        $results = $this->_db->fetchAll($select);
        
        return( $this->buildDocuments($results) );
    }
    
    /***
     * Gets information about a single document
     */
    public function getDocument($id)
    {
        $select = $this->_db->select()->from('documents')->where('doc_id = ?',$id);
        
        $result = $this->_db->fetchRow($select);
        
        return( $this->buildDocument($result) );
    }
    
    //Given a timespan bouned by the given start and end date
    //Gets the total miles of each case's visits within the timespan
    //Returns an associative array with the case_id as key and total miles as value
    //DOES NOT DISCRIMINATE BETWEEN OPEN AND CLOSED CASES
    public function getCaseVisitMiles($startDate, $endDate){
        $newStartDate = new Zend_Date($startDate, 'MM-dd-YYYY', 'en');
        $newStartDate = $newStartDate->get('YYYY-MM-dd');
        
        $newEndDate = new Zend_Date($endDate, 'MM-dd-YYYY', 'en');
        $newEndDate = $newEndDate->get('YYYY-MM-dd');
        
        $select = $this->_db->select()
                ->from(array('cc' => 'client_case'),
                       array('id' => 'cc.case_id',
                             'totalMiles' => 'cv.miles'))
                ->joinLeft(array('cv' => 'case_visit'), 'cc.case_id = cv.case_id')
                ->where('cv.visit_date >= ?', $newStartDate)
                ->where('cv.visit_date <= ?', $newEndDate);
        $results = $this->_db->fetchAll($select);
        $arr = array();
        foreach($results as $row){
            $report = new Application_Model_Impl_GenReport();
            $report->setCaseId($row['id']);
            $report->setTotalMiles($row['totalMiles']);
            $arr[] = $report;
        }
        $arr = $this->getNumMems($arr);
        return $arr;
    }
    
    //Given a timespan bouned by the given start and end date
    //Gets the total hours of each case's visits within the timespan
    //Returns an associative array with the case_id as key and total hours as value
    //DOES NOT DISCRIMINATE BETWEEN OPEN AND CLOSED CASES
    public function getCaseVisitHours($startDate, $endDate){
        $newStartDate = new Zend_Date($startDate, 'MM-dd-YYYY', 'en');
        $newStartDate = $newStartDate->get('YYYY-MM-dd');
        
        $newEndDate = new Zend_Date($endDate, 'MM-dd-YYYY', 'en');
        $newEndDate = $newEndDate->get('YYYY-MM-dd');
        
        $select = $this->_db->select()
                ->from(array('cc' => 'client_case'),
                       array('id' => 'cc.case_id',
                             'totalHours' => new Zend_Db_Expr('SUM(cv.hours)')))
                ->joinLeft(array('cv' => 'case_visit'), 'cc.case_id = cv.case_id')
                ->where('cv.visit_date >= ?', $newStartDate)
                ->where('cv.visit_date <= ?', $newEndDate)
                ->group('cc.case_id');
        $results = $this->_db->fetchAll($select);
        $arr = array();
        foreach($results as $row)
            $arr[$row['id']] = $row['totalHours'];
        return $arr;
    }
    
    //Gets all closed check requests (have an issue date)
    //Returns an array of populated CheckReq objects
    public function getClosedCheckReqs(){
        $select = $this->_db->select()
                ->from('check_request')
                ->where('issue_date IS NOT NULL');
        $results = $this->_db->fetchAll($select);
        $closedReqs = array();
        foreach($results as $row)
            $closedReqs[] = $this->buildCheckRequestModel($row);
        return $closedReqs;
    }
    
    //Gets the number of references and number of hmembers per case
    //Returns an array of populated GenReport objects
    //THIS WILL BE RENAMED TO REFLECT THE SPECIFIC REPORT THAT USES IT
    public function getGenReports($startDate, $endDate){
        $newStartDate = new Zend_Date($startDate, 'MM-dd-YYYY', 'en');
        $newStartDate = $newStartDate->get('YYYY-MM-dd');
        
        $newEndDate = new Zend_Date($endDate, 'MM-dd-YYYY', 'en');
        $newEndDate = $newEndDate->get('YYYY-MM-dd');
        return $this->getNumMems($this->getNumRefs($newStartDate, $newEndDate));
    }
    
    /****** PUBLIC EDIT/UPDATE/DELETE QUERIES  ******/
    
    // temp
    public function deleteDocument($doc)
    {
        $result = $this->_db->delete('documents','doc_id =' . $doc->getId());
        return $result;
    }
    
    /***
     * Updates information about a particular document
     */
    public function updateDocument($doc)
    {
        $data = array(  
                        'filename'    => $doc->getName(),
                        'url'         => $doc->getUrl(),
                        'internal_flag'    => $doc->isInternal());
        $where = "doc_id = " . $doc->getId();
        
        $this->_db->update('documents',$data,$where);
    }
    
    /****** PUBLIC CREATE/INSERT QUERIES ******/
    
    /**
     * Creates a new document
     */
    public function createDocument($doc)
    {
        $data = array(  'doc_id'      => null,
                        'filename'    => $doc->getName(),
                        'url'         => $doc->getUrl(),
                        'internal_flag'    => $doc->isInternal());
        $this->_db->insert('documents',$data);
    }
    
    /****** PRIVATE GET QUERIES  ******/
    
    //Returns an associative array with every case_id as key and 0 as value
    private function getAssocOfCases(){
        $select = $this->_db->select()
                ->from('client_case', array('id' => 'case_id'));
        $results = $this->_db->fetchAll($select);
        $ids = array();
        foreach($results as $row){
            $ids[$row['id']] = '0';
        }
        return $ids;
    }
    
    //Given an associative array of GenReport objects where key is case_id and value is the objects 
    //Gets the total number of household members associated with each case including the main client of he household
    //Returns the array with the NumHMembers populated
    private function getNumMems($arr){
        $select = $this->_db->select()
                ->from(array('cc' => 'client_case'),
                       array('id' => 'cc.case_id',
                             'totalMems' => new Zend_Db_Expr('COUNT(hmem.hmember_id)')))
                ->joinLeft(array('hmem' => 'hmember'),
                           'cc.household_id = hmem.household_id')
                ->group('cc.case_id');
        $results = $this->_db->fetchAll($select);
        $index = 0;
        foreach($results as $row){
            foreach($arr as $rep){
                if($row['id'] == $rep->getCaseId()){
                    $arr[$index]->setNumHMembers($row['totalMems'] + 1);
                }
                $index++;
            }
            $index = 0;
        }
        return $arr;
    }
    
    //Given a time span bounded by a start date and an end date (assured to be in international notation)
    //Gets the total number of referrals associated with each case
    //Returns an associative array with the key as 
    private function getNumRefs($newStartDate, $newEndDate){
        $select = $this->_db->select()
                ->from(array('cc' => 'client_case'),
                       array('id' => 'cc.case_id',
                             'totalRefs' => new Zend_Db_Expr('COUNT(r.referral_id)')))
                ->joinLeft(array('cn' => 'case_need'),
                           'cc.case_id = cn.case_id')
                ->joinLeft(array('r' => 'referral'),
                           'cn.caseneed_id = r.caseneed_id')
                ->where('r.referred_date >= ?', $newStartDate)
                ->where('r.referred_date <= ?', $newEndDate)
                ->group('cc.case_id');
        $results = $this->_db->fetchAll($select);
        $arr = array();
        foreach($results as $row){
            $report = new Application_Model_Impl_GenReport();
            $report->setCaseId($row['id']);
            $report->setNumRefs($row['totalRefs']);
            $arr[] = $report;
        }
        return $arr;
    }
    
    /****** IMPL OBJECT BUILDERS  ******/
    
    /***
     * Builds the list of docuemnts from a row set
     */
    private function buildDocuments($rowset)
    {
        $list = array();

        foreach($rowset as $row)
        {
            $doc = $this->buildDocument($row);

            array_push($list,$doc);
        }
        return($list);
    }
    
    /***
     * Builds a single document
     */
    private function buildDocument($row)
    {
        $doc = new Application_Model_Impl_Document();
        $doc
                ->setId($row['doc_id'])
                ->setUrl($row['url'])
                ->setName($row['filename'])
                ->setInternal($row['internal_flag']);
                
        return($doc);
    }
    
    //User and SigneeUser are the ids of the users, can change to objects if need be
    private function buildCheckRequestModel($results){
        $request = new Application_Model_Impl_CheckReq();
        $address = new Application_Model_Impl_Addr();
        $address
            ->setStreet($results['street'])
            ->setCity($results['city'])
            ->setState($results['state'])
            ->setZip($results['zipcode']);
        $request
            ->setId($results['checkrequest_id'])
            ->setCaseNeedId($results['caseneed_id'])
            ->setUser($results['user_id'])
            ->setRequestDate($results['request_date'])
            ->setAmount($results['amount'])
            ->setComment($results['comment'])
            ->setSigneeUser($results['signee_userid'])          
            ->setCheckNumber($results['check_number'])
            ->setIssueDate($results['issue_date'])
            ->setAccountNumber($results['account_number'])
            ->setPayeeName($results['payee_name'])
            ->setAddress($address)
            ->setPhone($results['phone'])
            ->setContactFirstName($results['contact_fname'])
            ->setContactLastName($results['contact_lname']);
        return $request;
    }  
}