function saveCorsOrigins(featureId) {
	let origins = document.getElementById("cors-origins").value;
	callFeature(featureId, "Save", "POST", { origins })
		.then(rs => {
			showOn("#cors-result", rs);
		});
}
