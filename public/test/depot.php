<?php include __DIR__ . '/_start.php'; ?>

<h1>Total CMS Depot Form Demo</h1>

<!-- {{ cms.form.depot("mydepot") }} -->

<form class="totalform" style="margin-top:2rem">
	<div class="depot-field">
		<div class="depot-layout">
			<div class="depot-browser-wrapper">
			<ul class="depot-browser">
				<li>
					<details open="">
						<summary class="folder">subfolder</summary>
						<ul class="folder-contents">
							<li>
								<details open="">
									<summary class="folder selected">another-folder</summary>
									<ul class="folder-contents">
										<li>
											<div class="file file-icon icon-zip">BrazilHeart-small-673d36552320b.png</div>
											<div class="size">3MB</div>
										</li>
										<li>
											<div class="file file-icon icon-png">BrazilHeart-small-673d365.png</div>
											<div class="size">200KB</div>
										</li>
										<li>
											<div class="file file-icon icon-zip">BrazilHeart.zip</div>
											<div class="size">3.5MB</div>
										</li>
									</ul>
								</details>
							</li>
							<li>
								<details open="">
									<summary class="folder">and-another-folder</summary>
									<ul class="folder-contents">
										<li>
											<div class="file file-icon icon-zip">BrazilHeart-small-673d36552320b.png</div>
											<div class="size">3MB</div>
										</li>
										<li>
											<div class="file file-icon icon-png">BrazilHeart-small-673d365.png</div>
											<div class="size">200KB</div>
										</li>
										<li>
											<div class="file file-icon icon-zip">BrazilHeart-small.png</div>
											<div class="size">3.5MB</div>
										</li>
									</ul>
								</details>
							</li>
							<li>
								<div class="file file-icon icon-zip">BrazilHeart-small-673d36552320b.png</div>
								<div class="size">3MB</div>
							</li>
							<li>
								<div class="file file-icon icon-png">BrazilHeart-small-673d365.png</div>
								<div class="size">200KB</div>
							</li>
							<li>
								<div class="file file-icon icon-zip">BrazilHeart-small.png</div>
								<div class="size">3.5MB</div>
							</li>
						</ul>
					</details>
				</li>
				<li>
					<details open="">
						<summary class="folder">subfolder</summary>
						<ul class="folder-contents">
							<li>
								<details open="">
									<summary class="folder">another-folder</summary>
									<ul class="folder-contents">
										<li>
											<div class="file file-icon icon-zip">BrazilHeart-small-673d36552320b.png</div>
											<div class="size">3MB</div>
										</li>
										<li>
											<div class="file file-icon icon-png">BrazilHeart-small-673d365.png</div>
											<div class="size">200KB</div>
										</li>
										<li>
											<div class="file file-icon icon-zip">BrazilHeart-small.png</div>
											<div class="size">3.5MB</div>
										</li>
									</ul>
								</details>
							</li>
							<li>
								<details open="">
									<summary class="folder">and-another-folder</summary>
									<ul class="folder-contents">
										<li>
											<div class="file file-icon icon-zip">BrazilHeart-small-673d36552320b.png</div>
											<div class="size">3MB</div>
										</li>
										<li>
											<div class="file file-icon icon-png">BrazilHeart-small-673d365.png</div>
											<div class="size">200KB</div>
										</li>
										<li>
											<div class="file file-icon icon-zip">BrazilHeart-small.png</div>
											<div class="size">3.5MB</div>
										</li>
									</ul>
								</details>
							</li>
							<li>
								<div class="file file-icon icon-zip">BrazilHeart-small-673d36552320b.png</div>
								<div class="size">3MB</div>
							</li>
							<li>
								<div class="file file-icon icon-png">BrazilHeart-small-673d365.png</div>
								<div class="size">200KB</div>
							</li>
							<li>
								<div class="file file-icon icon-zip">BrazilHeart-small.png</div>
								<div class="size">3.5MB</div>
							</li>
						</ul>
					</details>
				</li>
				<li>
					<div class="file file-icon icon-zip">BrazilHeart-small-673d36552320b.png</div>
					<div class="size">3MB</div>
				</li>
				<li>
					<div class="file file-icon icon-png">BrazilHeart-small-673d365.png</div>
					<div class="size">200KB</div>
				</li>
				<li>
					<div class="file file-icon icon-zip">BrazilHeart-small.png</div>
					<div class="size">3.5MB</div>
				</li>
			</ul>
			</div>
			<div class="depot-preview">
				<!-- <div class="folder-upload">
					<svg xmlns="http://www.w3.org/2000/svg" width="96" height="96" viewBox="0 0 64 64"><g fill="#222222"><path d="M17,4H10a5.937,5.937,0,0,0-6,6v7a1,1,0,0,0,2,0V10a3.957,3.957,0,0,1,4-4h7a1,1,0,0,0,0-2Z" fill="#222222"></path><path d="M54,4H47a1,1,0,0,0,0,2h7a3.957,3.957,0,0,1,4,4v7a1,1,0,0,0,2,0V10A5.937,5.937,0,0,0,54,4Z" fill="#222222"></path><path d="M59,46a1,1,0,0,0-1,1v7a3.957,3.957,0,0,1-4,4H47a1,1,0,0,0,0,2h7a5.937,5.937,0,0,0,6-6V47A1,1,0,0,0,59,46Z" fill="#222222"></path><path d="M17,58H10a3.957,3.957,0,0,1-4-4V47a1,1,0,0,0-2,0v7a5.937,5.937,0,0,0,6,6h7a1,1,0,0,0,0-2Z" fill="#222222"></path><path d="M25,6H39a1,1,0,0,0,0-2H25a1,1,0,0,0,0,2Z" fill="#222222"></path><path d="M39,58H25a1,1,0,0,0,0,2H39a1,1,0,0,0,0-2Z" fill="#222222"></path><path d="M59,24a1,1,0,0,0-1,1V39a1,1,0,0,0,2,0V25A1,1,0,0,0,59,24Z" fill="#222222"></path><path d="M5,40a1,1,0,0,0,1-1V25a1,1,0,0,0-2,0V39A1,1,0,0,0,5,40Z" fill="#222222"></path><path d="M32.781,15.375a1.036,1.036,0,0,0-1.562,0l-12,15A1,1,0,0,0,20,32h9V46a3,3,0,0,0,6,0V32h9a1,1,0,0,0,.781-1.625Z" fill="#222222"></path></g></svg>
					<h4 class="folder-name">another-folder</h4>
				</div> -->
				<div class="file-preview">
					<div class="file file-icon icon-zip">
						<h4 class="file-name">BrazilHeart.png</h4>
					</div>
					<div class="file-info">
						<div>
							<div class="info"><h6>Size</h6><span class="file-size">3.5MB</span></div>
							<div class="info"><h6>Date</h6><span class="file-date">2024/11/22 02:30PM</span></div>
							<div class="info"><h6>D.Count</h6><span class="file-count">10</span></div>
							<div class="info"><h6>D.Name</h6><span class="file-download">BrazilHeart-small-673d36552320b.png</span></div>
						</div>
						<div>
							<h6>Comments</h6>
							<p class="file-comments">Some comments here</p>
						</div>
						<div>
							<h6>Tags</h6>
							<div class="file-tags">
								<span>Tag1</span>
								<span>Tag2</span>
								<span>Tag3</span>
							</div>
						</div>
					</div>
				</div>
				<div class="actionbar">
					<button type="button" class="edit" title="Edit File Info"></button>
					<button type="button" disabled class="links" title="Download Links"></button>
					<button type="button" disabled class="download" title="Download File"></button>
					<button type="button" class="upload dz-clickable" title="Upload"></button>
					<button type="button" class="add-folder" title="New Folder"></button>
					<button type="button" class="trash" title="Delete File"></button>
				</div>
			</div>
		</div>
	</div>
</form>

<!-- <p>Max file upload size: <?php echo ini_get('upload_max_filesize'); ?></p>

<a href="/download/depot/mydepot/depot/">↓ Download Test</a> -->

<?php include __DIR__ . '/_end.php'; ?>