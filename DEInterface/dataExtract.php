<?php
	include_once('DEInterface.php');
	
	$deInterfaceObj = new DEInterface();
	$orgList = $deInterfaceObj->getLikeOrganizations();
	foreach ((array) $orgList as $item) {
		var_dump($item)."===<br/>";
	}
	// foreach($orgList as $key => $val ){
		// //echo '<option value="'.$orgDetails['orgID'].'">'.$orgDetails['orgID'].'</option>';
	// }

	
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
		.error{
			color:red;
			display:block;
		}
	</style>
</head>
<body>
	<div class="container">
		<br/><br/>
		<form class="form-data-extraction" method="post" action="">
			<div class="row">
				<div class="col-md-6">
					<div class="form-group">
						<?php 
							
							
						?>
						<select data-placeholder="Enter School Code" class="chosen-select" multiple style="width:100%;" tabindex="4" name="schoolCode" id="schoolCode">
							
						</select>  
						<span class="max-option-select-error error"></span>
					</div>
				</div>
			
				<div class="col-md-6">
					<div class="form-group">
						<label class="checkbox-inline">
							<input type="radio" name="isSpecificDataSelected" value="Specific">Specific
						</label>
						<label class="checkbox-inline">
							<input type="radio" name="isSpecificDataSelected" value="All">All
						</label>
					</div>
				</div>
			</div>
			<hr/>
			<div class="row">
				<div class="col-md-4 div-student">
					<div class="form-group">
					  <input type="text" class="form-control" name="studentName" id="studentName" placeholder="Student Name">
					</div>
				</div>
				<div class="col-md-2" >
					<div class="form-group">
					  <input type="text" class="form-control" name="batch" id="batch" placeholder="Academic Year">
					</div>
				</div>
				<div class="col-md-2" >
					<div class="form-group">
					  <input type="text" class="form-control" name="upID" id="upID" placeholder="UPID">
					</div>
				</div>
				<div class="col-md-2">
					<div class="form-group">
						<select data-placeholder="Select Section" class="chosen-select" multiple style="width:100%;" tabindex="4">
							<option value=""></option>
							<option value="A"> A</option>
							<option value="B">B</option>
							<option value="C">C</option>
							<option value="D">D</option>
						</select>  
					</div>
				</div>
				<div class="col-md-2">
					<div class="form-group">
						<select data-placeholder="Select Class" class="chosen-select" multiple style="width:100%;" tabindex="4">
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
						<input type="date" class="form-control" name="startDate" id="startDate" placeholder="Start Date">
					</div>
				</div>
				<div class="col-md-2">
					<div class="form-group">
						<input type="date" class="form-control" name="endDate" id="endDate" placeholder="End Date">
					</div>
				</div>
				<div class="col-md-12 text-center">
					<div class="form-group">
						<button type="submit" class="btn btn-success" name="btnSubmit" id="btnSubmit">Submit</button>
					</div>
				</div>
			</div>
		</form>
	</div>
	<script type="text/javascript">
		var config = {
			'.chosen-select'           : {max_selected_options:2},
			'.chosen-select-deselect'  : {allow_single_deselect:true},
			'.chosen-select-no-single' : {disable_search_threshold:10},
			'.chosen-select-no-single' : {disable_search_threshold:10},
			'.chosen-select-no-results': {no_results_text:'Oops, nothing found!'},
			'.chosen-select-width'     : {width:"95%"}
		}
		for (var selector in config) {
			$(selector).chosen(config[selector]);
		}
	</script>
	<script>

		$(document).ready(function(){
			$('.chosen-select').chosen({}).change( function(obj, result) {
				console.debug("changed: %o", arguments);
				
				console.log("selected: " + result.selected);
			});
			$(".chosen-select").bind("chosen:maxselected", function () {
						
				$('.max-option-select-error').html('You can select only two school code');
				
					setTimeout(function(){ 
						$('.max-option-select-error').html('');
												
					}, 4000);
			}); 
			
			$('#schoolCode').change(function(){
				//alert($(this).val());
			})

		});
	</script>
</body>
</html>
