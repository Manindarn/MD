<?php
session_start();
require_once ('define.php');
require_once('DEInterface.php');


$deInterfaceObj = new DEInterface();
$orgList = $deInterfaceObj->getLikeOrganizations();
//$fetchdata = $deInterfaceObj->fetch();

if(isset($_POST['btnUserLogin'])){

	$data = array('username'=>$_POST['username'], 'password'=>$_POST['password'],  'passwordType'=> "text");
	$result = $deInterfaceObj->login($data);

	if($result['resultCode'] == "C004" ){
		$_SESSION['isLoggedIn'] = true;
	}
}

        // Get MongoInputs //
	///$Postdata = false;

	if (isset($_POST["btnSubmit"])){
		//$Postdata = array('schoolCode'=>$_POST['schoolCode'], 'mongoSourceList'=>$_POST['mongoSourceList']);
		//	print_r('hi');
		  	//$Postdata = $_POST['mongoSourceList'];

		$org = $deInterfaceObj->fetch($_POST['schoolCode'],$_POST['mongoSourceList']);
		print_r($org);
		
} else {
	//echo $Postdata;
	echo "handle error here";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
	<title>DEInterface</title>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.0/css/bootstrap.min.css">
	<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>
	<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.0/js/bootstrap.min.js"></script>
	<script src="https://cdnjs.cloudflare.com/ajax/libs/chosen/1.4.2/chosen.jquery.min.js"></script>
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/chosen/1.4.2/chosen.min.css">
	<link href="https://fonts.googleapis.com/css?family=Roboto|Source+Sans+Pro" rel="stylesheet">
	<style>
		.chosen-container {
			width:100% !important;
		}
		.chosen-container-multi .chosen-choices li.search-choice{
			margin: 5px 5px 3px 0 !important;
		}
		.chosen-choices li.search-field input[type=text] {
	   		height: 31px !important;
		}
		.chosen-container-active.chosen-with-drop .chosen-single div b {
   			 background-position: -18px 6px !important;
		}
		.chosen-container-single .chosen-single {
   			padding: 4px 0 0 8px !important;
			height: 34px !important;
		}
		.error{
			color:red;
			display:block;
		}
		.lbl-block {
   			 display: block;
		}
		.bg-light-gray {
			background: #d5ffd5;
			padding-top: 8px;
			padding-bottom: 8px;
			padding-left: 12px;
		}
		.chk-block {
    		display: block;
		}

		.dataSourceList{
			display:none;
			max-height: 350px;
			overflow-y:scroll;
		}
   
		ul{
			list-style-type:none;
		}
		.dataSourceList ul {
   			padding: 3px 15px;
			list-style-type:none;
		}
		.dataSourceList .ul-sub li {
			display: block;
			padding: 4px 5px;
			border-bottom: 1px solid #93d693;
		}
		body{
			font-family: 'Source Sans Pro', sans-serif;
		}

		form.form-login {
			width: 350px;
			margin: 20px auto;
			padding: 9px 15px;
			background: #008fd5;
			min-height: 312px;
		}
		.user-login-head{
			margin: 17px 12px 40px 12px;
   			color: #fff;
		}
		#btnUserLogin {
			background-color: #b0d361;
			border-color: #b0d361;
			font-size: 14px;
			margin: 34px 0px;
			color: #525151;
			text-transform: uppercase;
			border-bottom: 4px solid#7da02e;
		}
	</style>
</head>
<body>
	<div class="container">
		<br/><br/>
		<?php if (isset($_SESSION['isLoggedIn']) AND $_SESSION['isLoggedIn'] == true ) { ?>
			<form class="form-data-extraction" method="post" action="">
				<div class="row">
					<div class="col-md-4 col-md-offset-3">
						<div class="form-group">
							<label class="lbl-block">School Code</label>
							<select data-placeholder="School Code" class="chosen-select" multiple name="schoolCode[]" id="schoolCode">
							<?php 
								foreach($orgList['orgList'] as $key => $val ){
									
									echo '<option value="'.$val['orgID'].'">'.$val['name'].'</option>';
								}
							?>
							</select>  
							<span class="max-option-select-error error"></span>
						</div>
					</div>
					<div class="col-md-3" >
						<div class="form-group">
							<label class="lbl-block">Academic Year</label>
							<select data-placeholder="Academic Year" class="chosen-select" name="batch" id="batch">

							</select>
						  
						</div>
					</div>
					
					<div class="col-md-12 text-center">
						<br/><br/>
						<div class="form-group">
							<label class="lbl-block">Do you want to select specific Class or All ?</label>
							<label class="checkbox-inline">
								<input type="radio" name="isSpecificDataSelected" value="Specific"> Specific
							</label>
							<label class="checkbox-inline">
								<input type="radio" name="isSpecificDataSelected" value="All"> All
							</label>
						</div>
					</div>


				</div>
				<div class="row class-section-row hide">
					<div class="col-md-2 col-md-offset-4">
						<div class="form-group">
							<label class="lbl-block">Class</label>
							<select data-placeholder="Select Class" class="chosen-select"  name="class[]" id="class" multiple>
								<option value=""></option>
								<option value="I">I</option>
								<option value="II">II</option>
								<option value="III">III</option>
								<option value="IV">IV</option>
								<option value="V">V</option>
								<option value="VI">VI</option>
								<option value="VII">VII</option>
							</select>  
						</div>
					</div>
					<div class="col-md-2">
						<div class="form-group">
							<label class="lbl-block">Section</label>
							<select data-placeholder="Select Section" class="chosen-select" name="sections[]" id="sections" multiple>
								<option value=""></option>
								<option value="A">A</option>
								<option value="B">B</option>
								<option value="C">C</option>
								<option value="D">D</option>
							</select>  
						</div>
					</div>
				</div>
				<div class="row">
					<div class="col-md-12 text-center">
						<div class="form-group">
							<label class="lbl-block">Do you want to select specific student in this ?</label>
							<label class="checkbox-inline">
								<input type="radio" name="isSpecificStudSelected" value="Specific"> Specific
							</label>
							<label class="checkbox-inline">
								<input type="radio" name="isSpecificStudSelected" value="All"> All
							</label>
						</div>
					</div>
					
					<div class="col-md-3 col-md-offset-3 div-student hide">
						<div class="form-group">
							<label class="lbl-block">Student Name</label>
							<select data-placeholder="Student" class="chosen-select"  name="studentName" id="studentName" >
							
							</select>
						</div>
					</div>
					<div class="col-md-2 div-start-date col-md-offset-4">
						<div class="form-group">
							<label class="lbl-block">Start Date</label>
							<input type="date" class="form-control" name="startDate" id="startDate" placeholder="Start Date">
						</div>
					</div>
					<div class="col-md-2">
						<div class="form-group">
							<label class="lbl-block">End Date</label>
							<input type="date" class="form-control" name="endDate" id="endDate" placeholder="End Date">
						</div>
					</div>

				</div>
				<div class="row">
					<label class="lbl-block text-center">Select Data Source</label>
					<div class="col-md-4 col-md-offset-2">
						<div class="form-group bg-light-gray">
							<label class="checkbox-inline chk-block">
								<input type="checkbox" name="dataSource[]" value="ElasticSearch" class="data-source-type"> ElasticSearch
							</label>
							<div class="dataSourceList elasticSources">
								<ul class="ul-sub bg-light-gray">
									<li><input type="checkbox" name="elasticSourceList[]" value="source1"> user_attemot_index</li>
									<li><input type="checkbox" name="elasticSourceList[]" value="source2"> user_module_progress_index</li>
									<li><input type="checkbox" name="elasticSourceList[]" value="source2"> user_session_log_index</li>
									<li><input type="checkbox" name="elasticSourceList[]" value="source2"> user_api_log</li>
								</ul>
							</div>
							
						</div>
					</div>
					<div class="col-md-4 ">
						<div class="form-group bg-light-gray">
							<label class="checkbox-inline chk-block">
								<input type="checkbox" name="dataSource[]" value="MongoDB" class="data-source-type"> MongoDB
							</label>
							<div class="dataSourceList mongoSources">
								<ul class="ul-main">
								<?php

									$mongoDefaultObject = new MongoDB('Organizations');
									$mongoDBs = $mongoDefaultObject->listMongoDBs();
									$hiddenDBsArray = array('admin', 'local');
									foreach($mongoDBs as $database){

										if(!in_array($database->getName(), $hiddenDBsArray)){
											echo '<li><input type="checkbox" name="" value="'.$database->getName().'"> '.$database->getName();

											$thisDBCollections = $mongoDefaultObject->listDBCollections($database->getName());
											
											echo '<ul class="ul-sub bg-light-gray">';
												$string = "Verticle";
												foreach ($thisDBCollections as $collection) {
													echo '<li><input type="checkbox" name="mongoSourceList[]" value="'.$database->getName().$string.$collection->getName().'"> '.$collection->getName().'</li>';
												}


											echo '</ul>';
										echo '</li>';
										}
									
									}
								?>
								</ul>
							</div>
							
						</div>
					</div>
				</div>
					<div class="col-md-12 text-center">
						<br/><br/>
						<div class="form-group">
							<button type="submit" class="btn btn-success" name="btnSubmit" id="btnSubmit">Submit</button>
						</div>
					</div>
				
			</form>
		<?php } 
		else { ?>
			<section class="section section-login">
				<form class="form-login" method="post" action="">
					<h3 class="text-center user-login-head">User Login</h3>
					
					<div class="form-group">
						<input type="text" class="form-control" name="username" id="username" placeholder="Username" value="">
					</div>
					<div class="form-group">
						<input type="password" class="form-control" name="password" id="password" placeholder="Password" value="">
					</div>
					<div class="form-group">
						<button type="submit" class="btn btn-primary btn-block" name="btnUserLogin" id="btnUserLogin">Login</button>
					</div>
				
				</form>
			</section>
	</div><!-- end of container -->
    <?php } ?>
	
	<script type="text/javascript">
		var config = {
			'#schoolCode' : {max_selected_options:2},
			'.chosen-select-width': {width:"100%"}
		}
		for (var selector in config) {
			$(selector).chosen(config[selector]);
		}
	</script>
	<script>
		$(document).ready(function(){
		
			$('.chosen-select').chosen();
			$("#schoolCode").bind("chosen:maxselected", function () {
				$('.max-option-select-error').html('You can select only two school code');
				setTimeout(function(){ 
					$('.max-option-select-error').html('');
											
				}, 4000);
			}); 
			
			$('#schoolCode').change(function(){
				$('#studentName, #class, #sections, #batch').empty();
				$('#studentName, #class, #sections, #batch').trigger("chosen:updated");		
				var schoolCodes = $(this).val();
				var isSpecificStudSelected = $("input[name='isSpecificStudSelected']:checked").val();
				if(schoolCodes.length > 0){

					if(isSpecificStudSelected =="Specific"){

						getOrgStudents();
					}

					$.ajax({
						url:'getSchoolAcademicYear.php',
						type:'post',
						data:{'orgID': schoolCodes, 'action':'getOrgBatches' },
						success:function(result){
							console.log(result.trim());
							var response = JSON.parse(result);

							var orgBatchArray = response.orgBatchArray;
							var orgClassArray = response.orgClassArray;
							var orgSectionArray = response.orgSectionArray;

							for (var i=0; i<orgBatchArray.length;i++){
								var found = 0;
								$('#batch').find('option').each(function() {
									if($(this).val() == orgBatchArray[i]['name']) found = 1;
								});

								if(found == 0)
									$('#batch').append($('<option>', { value: orgBatchArray[i]['name'], text : orgBatchArray[i]['name'] }));	
							}

							for (var i=0; i<orgSectionArray.length;i++){
								
								$('#class').append($('<option>', { value: orgClassArray[i], text : orgClassArray[i]}));
								
								$('#sections').append($('<option>', { value: orgSectionArray[i], text : orgSectionArray[i] }));
							}
							$('#studentName, #class, #sections, #batch').trigger("chosen:updated");	
						}
					});
				}
			})


			function checkValExistsInArray(val, arrayName){

				if($.inArray("test", arrayName) >= 0){

					return true;

				}else{
					return false;
				}
			}

			$('input[name="isSpecificDataSelected"]').click(function(){
				if($(this).val() == "Specific"){
					$('.class-section-row').show().removeClass('hide');
				}else{
					$('.class-section-row').hide();
				}
			});

			$('input[name="isSpecificStudSelected"]').click(function(){
				
				var isSpecificStudSelected = $("input[name='isSpecificStudSelected']:checked").val();

				if(isSpecificStudSelected == "Specific"){
					$('.div-student').show().removeClass('hide');
					$('.div-start-date').removeClass('col-md-offset-4');
					getOrgStudents();
				}else{
					$('.div-student').hide();
					$('.div-start-date').addClass('col-md-offset-4')
				}
			});


			$('.data-source-type').click(function(){

				$('.data-source-type').each(function () {
       				if($(this).is(":checked") ){
						$(this).closest('.form-group').find('.dataSourceList').show();
					}else{
						$(this).closest('.form-group').find('.dataSourceList').hide();
					}
    			});

				
			});

		});


		function getOrgStudents(){
			$('#studentName').empty();
			$('#studentName').trigger("chosen:updated");		
			var schoolCodes = $('#schoolCode').val();
			var isSpecificStudSelected = $("input[name='isSpecificStudSelected']:checked").val();
			
			if(isSpecificStudSelected =="Specific"){
				$.ajax({
					url:'getSchoolAcademicYear.php',
					type:'post',
					data:{'orgID': schoolCodes, 'action':'getOrgStudents' },
					success:function(result){
						console.log(result.trim());
						var response = JSON.parse(result);
						for (var i=0; i<response.length;i++){
							var found = 0;
							$('#studentName').find('option').each(function() {
								if($(this).val() == response[i]['name']) found = 1;
							});

							if(found == 0)
								$('#studentName').append($('<option>', { value: response[i]['name'], text : response[i]['name'] }));	
						}
						
						$('#studentName').trigger("chosen:updated");	
					}
				});
			}
		
		}
	</script>
</body>
</html>
