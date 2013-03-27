<?php
/*****************************************************************************************
 * X2CRM Open Source Edition is a customer relationship management program developed by
 * X2Engine, Inc. Copyright (C) 2011-2013 X2Engine Inc.
 * 
 * This program is free software; you can redistribute it and/or modify it under
 * the terms of the GNU Affero General Public License version 3 as published by the
 * Free Software Foundation with the addition of the following permission added
 * to Section 15 as permitted in Section 7(a): FOR ANY PART OF THE COVERED WORK
 * IN WHICH THE COPYRIGHT IS OWNED BY X2ENGINE, X2ENGINE DISCLAIMS THE WARRANTY
 * OF NON INFRINGEMENT OF THIRD PARTY RIGHTS.
 * 
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
 * FOR A PARTICULAR PURPOSE.  See the GNU Affero General Public License for more
 * details.
 * 
 * You should have received a copy of the GNU Affero General Public License along with
 * this program; if not, see http://www.gnu.org/licenses or write to the Free
 * Software Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA
 * 02110-1301 USA.
 * 
 * You can contact X2Engine, Inc. P.O. Box 66752, Scotts Valley,
 * California 95067, USA. or at email address contact@x2engine.com.
 * 
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU Affero General Public License version 3.
 * 
 * In accordance with Section 7(b) of the GNU Affero General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "Powered by
 * X2Engine" logo. If the display of the logo is not reasonably feasible for
 * technical reasons, the Appropriate Legal Notices must display the words
 * "Powered by X2Engine".
 *****************************************************************************************/

/**
 *  The Quotes module lets user's send people a quote with a list of products. Quotes can be converted to invoices.
 *
 *  Quotes can be created, updated, deleted, and converted into invoices from the contacts view. The code
 *  for that is in the file components/InlineQuotes.php and is heavily based on ajax calls to this controller.
 *  This controller includes functions actionQuickCreate, actionQuickDelete, and actionQuickUpdate which should be called via
 *  ajax. Those functions then call the components/InlineQuotes.php which returns a list of quotes to the client browser
 *  that made the ajax call. The function actionConvertToInvoice handles both ajax and non-ajax calls. If called via ajax,
 *  it will return the list of quotes for the contact id passed in the ajax call.
 *
 *
 * @package X2CRM.modules.quotes.controllers 
 */
class QuotesController extends x2base {

	public $modelClass = 'Quote';
		
	public function accessRules() {
		return array(
			array('allow',
				'actions'=>array('getItems'),
				'users'=>array('*'), 
			),
			array('allow', // allow authenticated user to perform 'create' and 'update' actions
				'actions'=>array('index', 'view', 'create', 'quickCreate', 'update', 'quickUpdate', 'search', 'addUser', 'addContact', 'removeUser', 'removeContact', 'saveChanges', 'print', 'delete', 'quickDelete', 'addProduct', 'deleteProduct', 'shareQuote', 'convertToInvoice', 'indexInvoice'),
				'users'=>array('@'),
			),
			array('allow', // allow admin user to perform 'admin' and 'delete' actions
				'actions'=>array('admin','testScalability'),
				'users'=>array('admin'),
			),
			array('deny',  // deny all users
				'users'=>array('*'),
			),
		);
	}
        
        public function actionGetItems(){
		$sql = 'SELECT id, name as value FROM x2_quotes WHERE name LIKE :qterm ORDER BY name ASC';
		$command = Yii::app()->db->createCommand($sql);
		$qterm = $_GET['term'].'%';
		$command->bindParam(":qterm", $qterm, PDO::PARAM_STR);
		$result = $command->queryAll();
		echo CJSON::encode($result); exit;
	}
		
	/**
	 * Displays a particular model.
	 * @param integer $id the ID of the model to be displayed
	 */
	public function actionView($id) {
		$type = 'quotes';
		$model = $this->loadModel($id);
		$contactId = $model->associatedContacts;
		$model->associatedContacts = Contacts::getContactLinks($model->associatedContacts);
				
		// find associated products and their quantities
		$quoteProducts = QuoteProduct::model()->findAllByAttributes(array('quoteId'=>$model->id));
		$orders = array(); // array of product-quantity pairs
		$total = 0; // total price for the quote
		foreach($quoteProducts as $qp) {
		    $price = $qp->price * $qp->quantity;
		    if($qp->adjustmentType == 'percent') {
		        $price += $price * ($qp->adjustment / 100);
		        $qp->adjustment = "{$qp->adjustment}%";
		    } else {
		    	$price += $qp->adjustment;
		    }
		    $orders[] = array(
		    	'name' => $qp->name,
		    	'id' => $qp->productId,
		    	'unit' => $qp->price,
		    	'quantity' => $qp->quantity,
				'adjustment' => $qp->adjustment,
		    	'price' => $price,
		    );
		    $order = end($orders);
		    $total += $order['price'];
		}
		
		$dataProvider = new CArrayDataProvider($orders, array(
		    'keyField'=>'name',
		    'sort'=>array(
		    	'attributes'=>array('name', 'unit', 'quantity', 'adjustment', 'price'),
		    ),
		    'pagination'=>array('pageSize'=>false),
		    
		));

		parent::view($model, $type, array('dataProvider'=>$dataProvider, 'total'=>$total, 'contactId'=>$contactId));
	}
	
	public function actionShareQuote($id){
		
		$model=$this->loadModel($id);
		$body="\n\n\n\n".Yii::t('quotes','Quote Record Details')." \n
".Yii::t('quotes','Name').": $model->name
".Yii::t('quotes','Description').": $model->description
".Yii::t('quotes','Quotes Stage').": $model->salesStage
".Yii::t('quotes','Lead Source').": $model->leadSource
".Yii::t('quotes','Probability').": $model->probability
".Yii::t('app','Link').": ".'http://'.Yii::app()->request->getServerName().$this->createUrl('/quotes/'.$model->id);
		
		$body = trim($body);

		$errors = array();
		$status = array();
		$email = '';
		if(isset($_POST['email'], $_POST['body'])){
		
			$subject = Yii::t('quotes','Quote Record Details');
			$email = $this->parseEmailTo($this->decodeQuotes($_POST['email']));
			$body = $_POST['body'];
			// if(empty($email) || !preg_match("/[a-zA-Z0-9._%-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,4}/",$email))
			if($email === false)
				$errors[] = 'email';
			if(empty($body))
				$errors[] = 'body';
			
			if(empty($errors))
				$status = $this->sendUserEmail($email,$subject,$body);

			if(array_search('200',$status)) {
				$this->redirect(array('view','id'=>$model->id));
				return;
			}
			if($email === false)
				$email = $_POST['email'];
			else
				$email = $this->mailingListToString($email);
		}
		$this->render('shareQuote',array(
			'model'=>$model,
			'body'=>$body,
			'currentWorkflow'=>$this->getCurrentWorkflow($model->id,'quotes'),
			'email'=>$email,
			'status'=>$status,
			'errors'=>$errors
		));
	}
	
	public function createQuote($model, $oldAttributes, $products){
		
		$model->createDate=time();
		$model->lastUpdated = time();
		$model->createdBy = Yii::app()->user->getName();
		$model->updatedBy = Yii::app()->user->getName();
		if($model->expectedCloseDate!=""){
				$model->expectedCloseDate=strtotime($model->expectedCloseDate);
		}
		
		$name=$this->modelClass;
		if($model->save()){
		
		    // $changes=$this->calculateChanges($oldAttributes, $model->attributes, $model);
		    // $this->updateChangelog($model,$changes);
            // $event=new Events;
            // $event->associationType=$name;
            // $event->associationId=$model->id;
            // $event->user=Yii::app()->user->getName();
            // $event->type='record_create';
		    // if($event->save() && $model->assignedTo!=Yii::app()->user->getName()){
			
				// $notif = new Notification;
				// $notif->user = $model->assignedTo;
				// $notif->createdBy = Yii::app()->user->getName();
				// $notif->createDate = time();
				// $notif->type = 'create';
				// $notif->modelType = $name;
				// $notif->modelId = $model->id;
				// $notif->save();
				
		        // $notif=new Notifications;
		        // $profile=X2Model::model('ProfileChild')->findByAttributes(array('username'=>$model->assignedTo));
		        // if(isset($profile))
		        	// $notif->text="$profile->fullName has created a(n) ".$name." for you";
		        // $notif->user=$model->assignedTo;
		        // $notif->createDate=time();
		        // $notif->viewed=0;
		        // $notif->record="$name:$model->id";
		        // $notif->save();
		    // }
		   	
		   	// tie contacts to quote
		   	/*
		   	foreach($contacts as $contact) {
		   		$relate = new Relationships;
		   		$relate->firstId = $model->id;
		   		$relate->firstType = "quotes";
		   		$relate->secondId = $contact;
		   		$relate->secondType = "contacts";
		   		$relate->save();
		   	} */
		   	
		   	// tie products to quote
		   	foreach($products as $product) {
		   		$qp = new QuoteProduct;
		   		$qp->quoteId = $model->id;
		   		$qp->productId = $product['id'];
		   		$qp->name = $product['name'];
		   		$qp->price = $product['price'];
		   		$qp->quantity = $product['quantity'];
		   		$qp->adjustment = $product['adjustment'];
		   		$qp->adjustmentType = $product['adjustmentType'];
		   		$qp->save();
		   	}
		    
			$this->redirect(array('view','id'=>$model->id));
		}else{
		    return false;
		}
	}

	/**
	 * Creates a new model.
	 * If creation is successful, the browser will be redirected to the 'view' page.
	 */
	public function actionCreate() {
		$model = new Quote;
		$users = User::getNames();
		
		$currency = Yii::app()->params->currency;
		$productNames = Product::productNames();
		$productCurrency = Product::productCurrency();

		// Uncomment the following line if AJAX validation is needed
		// $this->performAjaxValidation($model);

		if(isset($_POST['Quote'])) {
		/*
                    foreach($_POST as $key=>$arr){
                            $pieces=explode("_",$key);
                            if(isset($pieces[0]) && $pieces[0]=='autoselect'){
                                $newKey=$pieces[1];
                                if(isset($_POST[$newKey."_id"]) && $_POST[$newKey."_id"]!=""){
                                    $val=$_POST[$newKey."_id"];
                                }else{
                                    $field=Fields::model()->findByAttributes(array('fieldName'=>$newKey));
                                    if(isset($field)){
                                        $type=ucfirst($field->linkType);
                                        if($type!="Contacts"){
                                            eval("\$lookupModel=$type::model()->findByAttributes(array('name'=>'$arr'));");
                                        }else{
                                            $names=explode(" ",$arr);
                                            $lookupModel=X2Model::model('Contacts')->findByAttributes(array('firstName'=>$names[0],'lastName'=>$names[1]));
                                        }
                                        if(isset($lookupModel))
                                            $val=$lookupModel->id;
                                        else
                                            $val=$arr;
                                    }
                                }
                                $model->$newKey=$val;
                            }
                        }
//			$this->render('test', array('model'=>$_POST));
            $temp=$model->attributes;
        	foreach(array_keys($model->attributes) as $field){
                            if(isset($_POST['Quote'][$field])){
                                $model->$field=$_POST['Quote'][$field];
                                $fieldData=Fields::model()->findByAttributes(array('modelName'=>'Quotes','fieldName'=>$field));
                                if($fieldData->type=='assignment' && $fieldData->linkType=='multiple'){
                                    $model->$field=Accounts::parseUsers($model->$field);
                                }elseif($fieldData->type=='date'){
                                    $model->$field=strtotime($model->$field);
                                }
                                
                            }
                        }
        	        	
			$model->expirationDate = X2Model::parseDate($model->expirationDate);
        	*/
        	
        	/*
        	if(isset($model->associatedContacts)) {
        		$contacts = $model->associatedContacts;
        		$model->associatedContacts = Quote::parseContacts($model->associatedContacts);
        	} else {
        		$contacts = array();
        	}
        	*/
            $temp=$model->attributes;
			$model->setX2Fields($_POST['Quote']);


			// get products
                $products = array();
                if(isset($_POST['ExistingProducts'])){
			$ids = $_POST['ExistingProducts']['id'];
			$prices = $_POST['ExistingProducts']['price'];
			$quantities = $_POST['ExistingProducts']['quantity'];
			$adjustments = $_POST['ExistingProducts']['adjustment'];
			
			foreach($ids as $key=>$id) {
				if($id != 0) { // remove blanks
					$products[$key]['id'] = $id;
					$products[$key]['name'] = $productNames[$id];
					$products[$key]['price'] = $prices[$key];
					$products[$key]['quantity'] = $quantities[$key];
					if(strchr($adjustments[$key], '%')) { // percent adjustment
						$products[$key]['adjustment'] = intval(str_replace("%", "", $adjustments[$key]));
						$products[$key]['adjustmentType'] = 'percent';
					} else {
						$products[$key]['adjustment'] = $adjustments[$key];
						$products[$key]['adjustmentType'] = 'linear';
					}
				}
			}
			if(!empty($products))
				$currency = $productCurrency[$products[0]['id']];
                }
        	$model->currency = $currency;

			
        	$this->createQuote($model, $temp, $products);
		}

		$products = Product::activeProducts();
		$this->render('create',array(
			'model'=>$model,
			'users'=>$users,
			'products'=>$products,
			'productNames'=>$productNames,
		));
	}
	
	// create a quote from a mini Create Quote Form
	public function actionQuickCreate() {
		
		if(isset($_POST['Quote'])) {
			$model = new Quote;
			
            $oldAttributes = $model->attributes;
			$model->setX2Fields($_POST['Quote']);
			
			$contacts = $_POST['associatedContacts']; // get contacts
			$contact = X2Model::model('Contacts')->findByPk($contacts[0]);
			$model->associatedContacts = $contact->id;
			
			$redirect = $_POST['redirect'];
			
			// get product names
			$allProducts = Product::model()->findAll(array('select'=>'id, name, currency'));
			$productNames = array(0 => '');
			foreach($allProducts as $product) {
				$productNames[$product->id] = $product->name;
				$productCurrency[$product->id] = $product->currency;
			}
			$currency = Yii::app()->params->currency;

			
			// get products
			if(isset($_POST['ExistingProducts'])) {
				$ids = $_POST['ExistingProducts']['id'];
				$prices = $_POST['ExistingProducts']['price'];
				$quantities = $_POST['ExistingProducts']['quantity'];
				$adjustments = $_POST['ExistingProducts']['adjustment'];
				$products = array();
				foreach($ids as $key=>$id) {
					if($id != 0) { // remove blanks
						$products[$key]['id'] = $id;
						$products[$key]['name'] = $productNames[$id];
						$products[$key]['price'] = $prices[$key];
						$products[$key]['quantity'] = $quantities[$key];
						if(strchr($adjustments[$key], '%')) { // percent adjustment
							$products[$key]['adjustment'] = floatval(str_replace("%", "", $adjustments[$key]));
							$products[$key]['adjustmentType'] = 'percent';
						} else {
							$products[$key]['adjustment'] = $adjustments[$key];
							$products[$key]['adjustmentType'] = 'linear';
						}
					}
				}
			} else {
				$products = array();
			}
			
			if(!empty($products))
				$currency = $productCurrency[$products[0]['id']];
        	$model->currency = $currency;
			        	
			$model->createDate = time();
			$model->lastUpdated = time();
			$model->createdBy = Yii::app()->user->getName();
			$model->updatedBy = Yii::app()->user->getName();
			
			if($model->save()){
							
			    // $changes=$this->calculateChanges($oldAttributes, $model->attributes, $model);
			    // $this->updateChangelog($model,$changes);
			   	
			   	// tie contacts to quote
			   	/*
			   	foreach($contacts as $contactid) {
			   		$relate = new Relationships;
			   		$relate->firstId = $model->id;
			   		$relate->firstType = "quotes";
			   		$relate->secondId = $contactid;
			   		$relate->secondType = "contacts";
			   		$relate->save();
			   	} */
			   	
		   		// tie products to quote
		   		foreach($products as $product) {
		   			$qp = new QuoteProduct;
		   			$qp->quoteId = $model->id;
		   			$qp->productId = $product['id'];
		   			$qp->name = $product['name'];
		   			$qp->price = $product['price'];
		   			$qp->quantity = $product['quantity'];
		   			$qp->adjustment = $product['adjustment'];
		   			$qp->adjustmentType = $product['adjustmentType'];
		   			$qp->save();
		   		}
		   		
				// generate history
				$action = new Actions;
				$action->associationType = 'contacts';
				$action->type = 'quotes';
				$action->associationId = $contact->id;
				$action->associationName = $contact->name;
				$action->assignedTo = Yii::app()->user->getName();
				$action->completedBy=Yii::app()->user->getName();
				$action->createDate = time();
				$action->dueDate = time();
				$action->completeDate = time();
				$action->visibility = 1;
				$action->complete='Yes';
				$created = Yii::app()->dateFormatter->format(Yii::app()->locale->getDateFormat('long'), $model->createDate);
				$updated = Yii::app()->dateFormatter->format(Yii::app()->locale->getDateFormat('long'), $model->lastUpdated);
				$expires = Yii::app()->dateFormatter->format(Yii::app()->locale->getDateFormat('long'), $model->expirationDate);
			
				$description = "New Quote: <b>{$model->id}</b> {$model->name} ({$model->status})
				Created: <b>$created</b>
				Updated: <b>$updated</b> by <b>{$model->updatedBy}</b>
				Expires: <b>$expires</b>\n\n";

				$table = $model->productTable();
				$table = str_replace("\n", "", $table);
				$table = str_replace("\t", "", $table);
				$description .= $table;
				$action->actionDescription = $description;
				$action->save();
			}
			
			Yii::app()->clientScript->scriptMap['*.js'] = false;
			$contact = X2Model::model('Contacts')->findByPk($contacts[0]);
			$this->renderPartial('quoteFormWrapper', array('model'=>$contact), false, true);
		    
        }
	}
        
	public function updateQuote($model, $oldAttributes, $products) {
	    
	    $model->lastUpdated = time();
	    $model->updatedBy = Yii::app()->user->name;
	    
	    // $changes = $this->calculateChanges($oldAttributes, $model->attributes, $model);
	    // $model = $this->updateChangelog($model,$changes);
        
	    if($model->save()) {
	    	
	    	// update contacts
	    	/*
	    	$relationships = Relationships::model()->findAllByAttributes(
	    		array(
	    			'firstType'=>'quotes', 
	    			'firstId'=>$model->id, 
	    			'secondType'=>'contacts'
	    		)
	    	);
	    	foreach($relationships as $relate)
	    		if($key = array_search($relate->secondId, $contacts))
	    			unset($contacts[$key]);
	    		else
	    			$relate->delete();
	    	
	   		// tie new contacts to quote
	   		/*
	   		foreach($contacts as $contact) {
	   			$relate = new Relationships;
	   			$relate->firstId = $model->id;
	   			$relate->firstType = "quotes";
	   			$relate->secondId = $contact;
	   			$relate->secondType = "contacts";
	   			$relate->save();
	   		}
	   		*/
	   		
	   		// update products
	   		$orders = QuoteProduct::model()->findAllByAttributes(array('quoteId'=>$model->id));
	   		foreach($orders as $order) {
	   			$found = false;
	   			foreach($products as $key=>$product) {
	   				if($order->productId == $product['id']) {
	   					$order->price = $product['price'];
	   					$order->quantity = $product['quantity'];
	   					$order->adjustment = $product['adjustment'];
	   					$order->adjustmentType = $product['adjustmentType'];
	   					$order->save();
	   					unset($products[$key]);
	   					$found = true;
	   					break;
	   				}
	   			}
	   			if(!$found)
	   				$order->delete();
	   		}
	   		
	   		// tie new products to quote
	   		foreach($products as $product) {
		   		$qp = new QuoteProduct;
		   		$qp->quoteId = $model->id;
		   		$qp->productId = $product['id'];
		   		$qp->name = $product['name'];
		   		$qp->price = $product['price'];
		   		$qp->quantity = $product['quantity'];
		   		$qp->adjustment = $product['adjustment'];
		   		$qp->adjustmentType = $product['adjustmentType'];
		   		$qp->save();
	   		}
			
			$this->redirect(array('view','id'=>$model->id)); 
	    } else {
		    return false;
		}
	}
	
	/**
	 * Updates a particular model.
	 * If update is successful, the browser will be redirected to the 'view' page.
	 * @param integer $id the ID of the model to be updated
	 */
	public function actionUpdate($id) {
		$model=$this->loadModel($id);
		
		$users=User::getNames();
		
		// get associated contacts
		/*
		$relationships = Relationships::model()->findAllByAttributes(array('firstType'=>'quotes', 'firstId'=>$model->id, 'secondType'=>'contacts'));
		$selectedContacts = array();
		foreach($relationships as $relate) {
			$selectedContacts[] = $relate->secondId;
		}
		$model->associatedContacts = $selectedContacts; */
		$productNames = $model->productNames();
//                $fields=Fields::model()->findAllByAttributes(array('modelName'=>'Quote'));
//                foreach($fields as $field){
//                    if($field->type=='link'){
//                        $fieldName=$field->fieldName;
//                        $type=ucfirst($field->linkType);
//                        if(is_numeric($model->$fieldName) && $model->$fieldName!=0){
//                            eval("\$lookupModel=$type::model()->findByPk(".$model->$fieldName.");");
//                            if(isset($lookupModel))
//                                $model->$fieldName=$lookupModel->name;
//                        }
//                    }elseif($field->type=='date'){
//                        $fieldName=$field->fieldName;
//                        $model->$fieldName=date("Y-m-d",$model->$fieldName);
//                    }
//                }

		// Uncomment the following line if AJAX validation is needed
		// $this->performAjaxValidation($model);

		if(isset($_POST['Quote'])) {
            $model->setX2Fields($_POST['Quote']);
            $temp=$model->attributes;
        	/*
        	if(isset($model->associatedContacts)) {
        		$contacts = $model->associatedContacts;
        		$model->associatedContacts = Quote::parseContacts($model->associatedContacts);
        	} else {
        		$contacts = array();
        	} */
			
			// get products
			if(isset($_POST['ExistingProducts'])) {
				$ids = $_POST['ExistingProducts']['id'];
				$prices = $_POST['ExistingProducts']['price'];
				$quantities = $_POST['ExistingProducts']['quantity'];
				$adjustments = $_POST['ExistingProducts']['adjustment'];
				$products = array();
				foreach($ids as $key=>$id) {
					if($id != 0) { // remove blanks
						$products[$key]['id'] = $id;
						$products[$key]['name'] = $productNames[$id];
						$products[$key]['price'] = $prices[$key];
						$products[$key]['quantity'] = $quantities[$key];
						if(strchr($adjustments[$key], '%')) { // percent adjustment
							$products[$key]['adjustment'] = floatval(str_replace("%", "", $adjustments[$key]));
							$products[$key]['adjustmentType'] = 'percent';
						} else {
							$products[$key]['adjustment'] = $adjustments[$key];
							$products[$key]['adjustmentType'] = 'linear';
						}
					}
				}
			} else {
				$products = array();
			}
        	$this->updateQuote($model, $temp, $products);

		}
//		if(!empty($model->expirationDate)) // format expiration date
//			$model->expirationDate = Yii::app()->dateFormatter->format('MMM dd, yyyy', $model->expirationDate);
		$products = $model->activeProducts();
		$orders = QuoteProduct::model()->findAllByAttributes(array('quoteId'=>$model->id));
		$this->render('update',array(
			'model'=>$model,
			'users'=>$users,
			'products'=>$products,
			'productNames'=>$productNames,
			'orders'=>$orders,
		));
	}
	
	// called from the quotes mini module in contacts view
	public function actionQuickUpdate($id) {
		$model = $this->loadModel($id);

        foreach(array_keys($model->attributes) as $field){
                            if(isset($_POST['Quote'][$field])){
                                $model->$field=$_POST['Quote'][$field];
                                $fieldData=Fields::model()->findByAttributes(array('modelName'=>'Quote','fieldName'=>$field));
                                if($fieldData->type=='assignment' && $fieldData->linkType=='multiple'){
                                    $model->$field=Accounts::parseUsers($model->$field);
                                }elseif($fieldData->type=='date'){
                                    $model->$field=X2Model::parseDate($model->$field);
                                }
                                
                            }
                        }
            	
        $model->save();
        
		$allProducts = Product::model()->findAll(array('select'=>'id, name, price'));
		$productNames = array(0 => '');
		foreach($allProducts as $product) {
		    $productNames[$product->id] = $product->name;
		}
		
	    $model->lastUpdated = time();
	    $model->updatedBy = Yii::app()->user->name;
			
		// get products
		if(isset($_POST['ExistingProducts'])) {
		    $ids = $_POST['ExistingProducts']['id'];
		    $prices = $_POST['ExistingProducts']['price'];
		    $quantities = $_POST['ExistingProducts']['quantity'];
		    $adjustments = $_POST['ExistingProducts']['adjustment'];
		    $products = array();
		    foreach($ids as $key=>$id) {
		        if($id != 0) { // remove blanks
		        	$products[$key]['id'] = $id;
		        	$products[$key]['name'] = $productNames[$id];
		        	$products[$key]['price'] = $prices[$key];
		        	$products[$key]['quantity'] = $quantities[$key];
		        	if(strchr($adjustments[$key], '%')) { // percent adjustment
		        		$products[$key]['adjustment'] = floatval(str_replace("%", "", $adjustments[$key]));
		        		$products[$key]['adjustmentType'] = 'percent';
		        	} else {
		        		$products[$key]['adjustment'] = $adjustments[$key];
		        		$products[$key]['adjustmentType'] = 'linear';
		        	}
		        }
		    }
		
	   	    // update products
	   	    $orders = QuoteProduct::model()->findAllByAttributes(array('quoteId'=>$model->id));
	   	    foreach($orders as $order) {
	   	        $found = false;
	   	        foreach($products as $key=>$product) {
	   	        	if($order->productId == $product['id']) {
	   	        		$order->price = $product['price'];
	   	        		$order->quantity = $product['quantity'];
	   	        		$order->adjustment = $product['adjustment'];
	   	        		$order->adjustmentType = $product['adjustmentType'];
	   	        		$order->save();
	   	        		unset($products[$key]);
	   	        		$found = true;
	   	        		break;
	   	        	}
	   	        }
	   	        if(!$found)
	   	        	$order->delete();
	   	    }
	   	    
	   	    // tie new products to quote
	   	    foreach($products as $product) {
		        $qp = new QuoteProduct;
		        $qp->quoteId = $model->id;
		        $qp->productId = $product['id'];
		        $qp->name = $product['name'];
		        $qp->price = $product['price'];
		        $qp->quantity = $product['quantity'];
		        $qp->adjustment = $product['adjustment'];
		        $qp->adjustmentType = $product['adjustmentType'];
		        $qp->save();
	   	    }
	   	}
	   	
	   	$contact = X2Model::model('Contacts')->findByPk($_POST['contactId']);
	   	
		// generate history
		$action = new Actions;
		$action->associationType = 'contacts';
		$action->type = 'quotes';
		$action->associationId = $contact->id;
		$action->associationName = $contact->name;
		$action->assignedTo = Yii::app()->user->getName();
		$action->completedBy=Yii::app()->user->getName();
		$action->dueDate = time();
		$action->completeDate = time();
		$action->visibility = 1;
		$action->complete='Yes';
		$created = Yii::app()->dateFormatter->format(Yii::app()->locale->getDateFormat('long'), $model->createDate);
		$updated = Yii::app()->dateFormatter->format(Yii::app()->locale->getDateFormat('long'), $model->lastUpdated);
		$expires = Yii::app()->dateFormatter->format(Yii::app()->locale->getDateFormat('long'), $model->expirationDate);
		
		$description = "Updated Quote
		<span style=\"font-weight: bold; font-size: 1.25em;\">{$model->id}</span> {$model->name} ({$model->status})
		Created: <b>$created</b>
		Updated: <b>$updated</b> by <b>{$model->updatedBy}</b>
		Expires: <b>$expires</b>\n\n";
		
		$table = $model->productTable();
		$table = str_replace("\n", "", $table);
		$table = str_replace("\t", "", $table);
		$description .= $table;
		$action->actionDescription = $description;
		$action->save();
		
		if(isset($_POST['contactId'])) {
		    Yii::app()->clientScript->scriptMap['*.js'] = false;
		    $contact = X2Model::model('Contacts')->findByPk($_POST['contactId']);
		    $this->renderPartial('quoteFormWrapper', array('model'=>$contact), false, true);
		}
	}
	
	/**
	 *  Print a quote.
	 *
	 *  First, display a page for print options, then when that page is submitted,
	 *  display a printer friendly quotes or invoice page.
	 *
	 */
	public function actionPrint($id) {
		$model = $this->loadModel($id);
		
		if(isset($_POST['Quote'])) {
		
			if(isset($_POST['includeNotes']))
				$includeNotes = $_POST['includeNotes'];
			else
				$includeNotes = false;

			if(isset($_POST['includeLogo']))
				$includeLogo = $_POST['includeLogo'];
			else
				$includeLogo = false;

			if(isset($_POST['Quote']['description']))
				$notes = $_POST['Quote']['description'];
			else
				$notes = '';
			
			$this->renderPartial('print',
				array(
					'model'=>$model,
					'includeNotes'=>$includeNotes,
					'notes'=>$notes,
					'includeLogo'=>$includeLogo,
				)
			);
		} else {
			$this->renderPartial('printOptions', array('model'=>$model));
		}
	}

	public function actionAddUser($id) {
		$users=User::getNames();
		$contacts=Contacts::getAllNames();
		$model=$this->loadModel($id);
		$users=Quote::editUserArray($users,$model);

		// Uncomment the following line if AJAX validation is needed
		// $this->performAjaxValidation($model);

		if(isset($_POST['Quote'])) {
			$temp=$model->assignedTo; 
                        $tempArr=$model->attributes;
			$model->attributes=$_POST['Quote'];  
			$arr=$model->assignedTo;
			

			$model->assignedTo=Quote::parseUsers($arr);
			if($temp!="")
				$temp.=", ".$model->assignedTo;
			else
				$temp=$model->assignedTo;
			$model->assignedTo=$temp;
			// $changes=$this->calculateChanges($tempArr,$model->attributes);
			// $model=$this->updateChangelog($model,$changes);
			if($model->save())
				$this->redirect(array('view','id'=>$model->id));
		}

		$this->render('addUser',array(
			'model'=>$model,
			'users'=>$users,
			'contacts'=>$contacts,
			'action'=>'Add'
		));
	}

	public function actionAddContact($id) {
		$users=User::getNames();
		$contacts=Contacts::getAllNames();
		$model=$this->loadModel($id);

		$contacts=Quote::editContactArray($contacts, $model);

		// Uncomment the following line if AJAX validation is needed
		// $this->performAjaxValidation($model);

		if(isset($_POST['Quote'])) {
			$temp=$model->associatedContacts; 
            $tempArr=$model->attributes;
			$model->attributes=$_POST['Quote'];  
			$arr=$model->associatedContacts;
            foreach($arr as $contactId) {
                $rel=new Relationships;
                $rel->firstType='quotes';
                $rel->firstId=$model->id;
                $rel->secondType='contacts';
                $rel->secondId=$contactId;
                $rel->save();
            }
			

			$model->associatedContacts=Quote::parseContacts($arr);
			$temp.=" ".$model->associatedContacts;
			$model->associatedContacts=$temp;
			// $changes=$this->calculateChanges($tempArr,$model->attributes);
			// $model=$this->updateChangelog($model,$changes);
			if($model->save())
				$this->redirect(array('view','id'=>$model->id));
		}

		$this->render('addContact',array( 
			'model'=>$model,
			'users'=>$users,
			'contacts'=>$contacts,
			'action'=>'Add'
		));
	}

	public function actionRemoveUser($id) {

		$model=$this->loadModel($id);

		$pieces=explode(', ',$model->assignedTo);
		$pieces=Quote::editUsersInverse($pieces);

		// Uncomment the following line if AJAX validation is needed
		// $this->performAjaxValidation($model);

		if(isset($_POST['Quote'])) {
			$temp=$model->attributes;
			$model->attributes=$_POST['Quote'];  
			$arr=$model->assignedTo;
			
			
			foreach($arr as $id=>$user)
				unset($pieces[$user]);
			
			$model->assignedTo = Quote::parseUsersTwo($pieces);
			// $changes=$this->calculateChanges($temp,$model->attributes);
			// $model=$this->updateChangelog($model,$changes);
			if($model->save())
				$this->redirect(array('view','id'=>$model->id));
		}

		$this->render('addUser',array(
			'model'=>$model,
			'users'=>$pieces,
			'action'=>'Remove'
		));
	}

	public function actionRemoveContact($id) {

		$model=$this->loadModel($id);
		$pieces=explode(" ",$model->associatedContacts);
		$pieces=Quote::editContactsInverse($pieces);

		// Uncomment the following line if AJAX validation is needed
		// $this->performAjaxValidation($model);

		if(isset($_POST['Quote'])) {
            $temp=$model->attributes;
			$model->attributes=$_POST['Quote'];  
			$arr=$model->associatedContacts;
			
			
			foreach($arr as $id=>$contact) {
				$rel=X2Model::model('Relationships')->findByAttributes(
					array(
						'firstType'=>'Contacts',
						'firstId'=>$contact,
						'secondType'=>'Quotes',
						'secondId'=>$model->id
					)
				);
				if(isset($rel))
					$rel->delete();
				unset($pieces[$contact]);
			}
			
			$model->associatedContacts = Quote::parseContactsTwo($pieces);
			// $changes=$this->calculateChanges($temp,$model->attributes);
			// $model=$this->updateChangelog($model,$changes);
			if($model->save())
				$this->redirect(array('view','id'=>$model->id));
		}

		$this->render('addContact',array(
			'model'=>$model,
			'contacts'=>$pieces,
			'action'=>'Remove'
		));
	}

	/**
	 * Lists all models.
	 */
	public function actionIndex() {
		$model=new Quote('search');
		$this->render('index', array('model'=>$model));
	}
	
	/**
	 * Lists all models.
	 *
	 *  This is a separate list for invoices. An invoice is a quote
	 *  with field type='invoice'. The only difference is that when listing,
	 *  printing, or emailing an invoice, we call it an invoice instead of a
	 *  quote.
	 */
	public function actionIndexInvoice() {
		$model=new Quote('searchInvoice');
		$this->render('indexInvoice', array('model'=>$model));
	}

	/**
	 * Returns the data model based on the primary key given in the GET variable.
	 * If the data model is not found, an HTTP exception will be raised.
	 * @param integer the ID of the model to be loaded
	 */
	public function loadModel($id)
	{
		$model=Quote::model()->findByPk((int)$id);
		if($model===null)
			throw new CHttpException(404,Yii::t('app','The requested page does not exist.'));
		return $model;
	}
        
        public function delete($id){
            $model=$this->loadModel($id);
            $dataProvider=new CActiveDataProvider('Actions', array(
                    'criteria'=>array(
                    'condition'=>'associationId='.$id.' AND associationType=\'quote\'',
            )));
            $actions=$dataProvider->getData();
            foreach($actions as $action){
                    $action->delete();
            }
            $this->cleanUpTags($model);
            $model->delete();
        }

	public function actionDelete($id) {
		$model=$this->loadModel($id);
		if(Yii::app()->request->isPostRequest) {
            $event=new Events;
            $event->type='record_deleted';
            $event->associationType=$this->modelClass;
            $event->associationId=$model->id;
            $event->text=$model->name;
            $event->user=Yii::app()->user->getName();
            $event->save();
			
			// delete associated actions
			Actions::model()->deleteAllByAttributes(array('associationId'=>$id, 'associationType'=>'quotes'));
			// delete product relationships
			QuoteProduct::model()->deleteAllByAttributes(array('quoteId'=>$id));
			// delete contact relationships
			Relationships::model()->deleteAllByAttributes(array('firstType'=>'quotes', 'firstId'=>$id, 'secondType'=>'contacts'));
			
            $this->cleanUpTags($model);
			$model->delete();
		} else
			throw new CHttpException(400,Yii::t('app','Invalid request. Please do not repeat this request again.'));
			// if AJAX request (triggered by deletion via admin grid view), we should not redirect the browser
			
		if(!isset($_GET['ajax']))
			$this->redirect(isset($_POST['returnUrl']) ? $_POST['returnUrl'] : array('index'));
	}
	
	public function actionQuickDelete($id) {
		$model=$this->loadModel($id);
		
		if($model) {

			// delete associated actions
			Actions::model()->deleteAllByAttributes(array('associationId'=>$id, 'associationType'=>'quotes'));
			// delete product relationships
			QuoteProduct::model()->deleteAllByAttributes(array('quoteId'=>$id));
			// delete contact relationships
			Relationships::model()->deleteAllByAttributes(array('firstType'=>'quotes', 'firstId'=>$id, 'secondType'=>'contacts'));

			$name = $model->name;

			// generate history
			
			$contact = X2Model::model('Contacts')->findByPk($_GET['contactId']);

			$action = new Actions;
			$action->associationType = 'contacts';
			$action->type = 'quotes';
			$action->associationId = $contact->id;
			$action->associationName = $contact->name;
			$action->assignedTo = Yii::app()->user->getName();
			$action->completedBy=Yii::app()->user->getName();
			$action->createDate = time();
			$action->dueDate = time();
			$action->completeDate = time();
			$action->visibility = 1;
			$action->complete='Yes';
			$action->actionDescription = "Deleted Quote: <span style=\"font-weight:bold;\">{$model->id}</span> {$model->name}";
			$action->save();
	
            $this->cleanUpTags($model);
			$model->delete();
			
		}  else 
			throw new CHttpException(400,Yii::t('app','Invalid request. Please do not repeat this request again.'));
		
		if($_GET['contactId']) {
			Yii::app()->clientScript->scriptMap['*.js'] = false;
			$contact = X2Model::model('Contacts')->findByPk($_GET['contactId']);
			$this->renderPartial('quoteFormWrapper', array('model'=>$contact), false, true);
		}
	}
	
	// delete a product from a quote
	public function actionAddProduct($id) {
		$model=$this->loadModel($id);
				
		if(isset($_POST['ExistingProducts'])) {
			// get products
			$ids = $_POST['ExistingProducts']['id'];
			$quantities = $_POST['ExistingProducts']['quantity'];
			$products = array();
			foreach($ids as $key=>$id) {
				if($id != 0) { // remove blanks
					$products[$key]['id'] = $id;
					$products[$key]['quantity'] = $quantities[$key];
				}
			}
			// tie products to quote
			foreach($products as $product) {
			    $qp = new QuoteProduct;
			    $qp->quoteId = $model->id;
			    $qp->productId = $product['id'];
			    $qp->quantity = $product['quantity'];
			    $qp->save();
			}
			
			if(isset($_POST['contactId'])) {
				Yii::app()->clientScript->scriptMap['*.js'] = false;
				$contact = X2Model::model('Contacts')->findByPk($_POST['contactId']);
				$this->renderPartial('quoteFormWrapper', array('model'=>$contact), false, true);
			}
		}
	}
	
	/**
	 *  Convert the Quote into an Invoice
	 *  An invoice is a quote with field type='invoice'. The only difference is that 
	 *  when listing, printing, or emailing an invoice, we call it an invoice instead
	 *  of a quote.
	 *
	 *  @param $id id of the quote to convert to invoice
	 *
	 */
	public function actionConvertToInvoice($id) {
		$model=$this->loadModel($id); // get model
		
		// convert to invoice
		$model->type = 'invoice';
		$model->invoiceCreateDate = time();
				
		// set invoice status to the top choice in the invoice status drop down
		$field = $model->getField('invoiceStatus'); 
		if($field) {
			$dropDownId = $field->linkType;
			if($dropDownId) {
				$dropdowns = Dropdowns::getItems($field->linkType);
				if($dropdowns) {
					reset($dropdowns);
					$status = key($dropdowns);
					if($status) {
						$model->invoiceStatus = $status;
					}
				}
			}
		}
		
		$model->update();
		
		if(isset($_GET['contactId'])) { // ajax request from a contact view, don't reload page, instead return a list of quotes for this contact
			if(isset($_GET['contactId'])) {
				$contact = X2Model::model('Contacts')->findByPk($_GET['contactId']);
				if($contact) {
					Yii::app()->clientScript->scriptMap['*.js'] = false;
					$this->renderPartial('quoteFormWrapper', array('model'=>$contact), false, true);
					return;
				}
			}
		}
		
		$this->redirect(array('view','id'=>$model->id)); // view quote
	}
		
	// delete a product from a quote
	public function actionDeleteProduct($id) {
		$model=$this->loadModel($id);
		
		if(isset($_GET['productId']))
			QuoteProduct::model()->deleteAllByAttributes(array('quoteId'=>$id, 'productId'=>$_GET['productId']));
		
		if($_GET['contactId']) {
			Yii::app()->clientScript->scriptMap['*.js'] = false;
			$contact = X2Model::model('Contacts')->findByPk($_GET['contactId']);
			$this->renderPartial('quoteFormWrapper', array('model'=>$contact), false, true);
		}
	}
	
	public function actionGetTerms(){
		$sql = 'SELECT id, name as value FROM x2_accounts WHERE name LIKE :qterm ORDER BY name ASC';
		$command = Yii::app()->db->createCommand($sql);
		$qterm = $_GET['term'].'%';
		$command->bindParam(":qterm", $qterm, PDO::PARAM_STR);
		$result = $command->queryAll();
		echo CJSON::encode($result); exit;
	}
}