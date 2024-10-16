<?php include __DIR__ . '/_start.php'; ?>

<h1>Total CMS File Form Demo</h1>

{{ cms.form.image("myimage") }}

<form class="totalform edit-mode" data-schema="file" data-collection="file" data-method="PUT" data-api="https://totalcms.test" data-route="/collections/file/myfile" data-id="myfile">
	<div class="form-field hidden-field " data-type="hidden">
		<div class="form-group"><input type="hidden" id="field-670ea4e9cc28d" name="id" required="" value="myimage"></div>
	</div>
	<div class="form-field file-field " data-type="file"><label for="field-670ea4e9cc2ca">File</label>
		<div class="form-group">
			<input id="field-670ea4e9cc2ca" type="text" name="file">
			<div class="dz-overlay dz-clickable"></div>
			<div class="total-preview">
				<div class="file-preview">
					<div class="dz-preview dz-file-preview">
						<div class="actionbar">
							<button type="button" class="edit" title="Edit File Info"></button>
							<button type="button" class="links" title="Copy Download Link"></button>
							<button type="button" class="macro" title="Copy Download Macro"></button>
							<button type="button" class="download" title="Download File"></button>
							<button type="button" class="upload dz-clickable" title="Upload New File"></button>
							<button type="button" class="trash" title="Delete File"></button>
						</div>
						<div class="file-icon"></div>
						<p class="filename">myfile-with-longer-name.zip</p>
						<div class="dz-progress">
							<span class="dz-upload" data-dz-uploadprogress=""></span>
							<span class="dz-upload-progress-label" data-dz-uploadprogress="">0%</span>
							<div class="dz-status"></div>
						</div>
					</div>
					<dialog class="cms-modal file-edit-dialog">
						<section class="scroller">
							<details class="cms-accordion ">
								<summary>Info</summary>
								<div class="content">
									<div class="form-field text-field " data-type="text"><label for="field-670ea4e9cc39d">Download Name</label>
										<div class="form-group"><input type="text" id="field-670ea4e9cc39d" name="name" placeholder="myfile.zip" aria-describedby="help-670ea4e9cc39d">
											<div class="form-group-icon"></div>
										</div>
										<p class="help" id="help-670ea4e9cc39d">The name of the file when it gets downloaded</p>
									</div>
									<div class="form-field text-field " data-type="text"><label for="field-670ea4e9cc372">Comments</label>
										<div class="form-group"><input type="text" id="field-670ea4e9cc372" name="comments" placeholder="File Comments" aria-describedby="help-670ea4e9cc372">
											<div class="form-group-icon"></div>
										</div>
										<p class="help" id="help-670ea4e9cc372">Comments about this file</p>
									</div>
									<div class="form-field list-field " data-type="list"><label for="field-670ea4e9cc3dc">Tags</label>
										<div class="form-group">
											<div class="choices" data-type="select-multiple" role="combobox" aria-autocomplete="list" aria-haspopup="true" aria-expanded="false">
												<div class="choices__inner"><select id="field-670ea4e9cc3dc" name="tags" multiple="" size="1" aria-describedby="help-670ea4e9cc3dc" class="choices__input" hidden="" tabindex="-1" data-choice="active">
														<option value="" disabled="">Add Tags</option>
													</select>
													<div class="choices__list choices__list--multiple" role="listbox"></div><input type="search" class="choices__input choices__input--cloned" autocomplete="off" autocapitalize="off" spellcheck="false" role="textbox" aria-autocomplete="list" aria-label="Add Tags" placeholder="Add Tags" style="min-width: 9ch; width: 1ch;">
												</div>
												<div class="choices__list choices__list--dropdown" aria-expanded="false">
													<div class="choices__list" aria-multiselectable="true" role="listbox">
														<div class="choices__item choices__item--choice choices__notice has-no-choices">No choices to choose from</div>
													</div>
												</div>
											</div>
											<div class="form-group-icon"></div>
										</div>
										<p class="help" id="help-670ea4e9cc3dc">Add tags to help organize your file.</p>
									</div>
								</div>
							</details>
							<details class="cms-accordion ">
								<summary>Protection</summary>
								<div class="content">
									<div class="form-field checkbox-field " data-type="checkbox">
										<div class="form-group"><input id="field-670ea4e9cc34a" name="featured" type="checkbox" aria-describedby="help-670ea4e9cc34a"><label for="field-670ea4e9cc34a">Protected by Collection</label></div>
										<p class="help" id="help-670ea4e9cc34a">Access group protection is set in the Collection.</p>
									</div>
									<div class="form-field password-field " data-type="password"><label for="field-670ea4e9cc39d">Password</label>
										<div class="form-group"><input type="password" id="field-670ea4e9cc39d" name="password" placeholder="secret" aria-describedby="help-670ea4e9cc39d">
											<div class="form-group-icon"></div>
										</div>
										<p class="help" id="help-670ea4e9cc39d">Require a password to download this file. This overrides all collection level access controls.</p>
									</div>
								</div>
							</details>
							<details class="cms-accordion ">
								<summary>Meta (Readonly)</summary>
								<div class="content">
									<div class="form-field text-field " data-type="text"><label for="field-670ea4e9cc6b6">Filename</label>
										<div class="form-group"><input type="text" id="field-670ea4e9cc6b6" name="name" readonly="" value="World Travel Sunset (1).jpeg"></div>
									</div>
									<div class="form-field text-field " data-type="text"><label for="field-670ea4e9cc6cc">MIME Type</label>
										<div class="form-group"><input type="text" id="field-670ea4e9cc6cc" name="mime" readonly="" value="image/jpeg"></div>
									</div>
									<div class="form-field number-field " data-type="number"><label for="field-670ea4e9cc69f">Size</label>
										<div class="form-group"><input type="number" id="field-670ea4e9cc69f" name="size" readonly="" value="185205"></div>
									</div>
									<div class="form-field datetime-field " data-type="datetime"><label for="field-670ea4e9cc6e4">Upload Date</label>
										<div class="form-group"><input type="datetime-local" id="field-670ea4e9cc6e4" name="uploadDate" readonly="" value="2024-09-24T09:54"></div>
									</div>
								</div>
							</details>
						</section>
						<section><button type="button" class="button btn close">Close</button></section>
					</dialog>
				</div>
			</div>
		</div>
		<p class="help" id="help-670ea4e9cc2ca">Drag and drop an file here.</p>
	</div>
</form>

<?php include __DIR__ . '/_end.php'; ?>