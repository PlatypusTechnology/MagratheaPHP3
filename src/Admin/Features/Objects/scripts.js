
function objectsContext() {
	return {
		feature: $("#objects-feature-id").val(),
		object: $("#objects-class").val(),
	};
}

function objectsParse(rs) {
	if(typeof rs !== "string") return rs;
	try {
		return JSON.parse(rs);
	} catch(e) {
		return { success: false, error: "unexpected response" };
	}
}

function objectsLoadList(page) {
	let ctx = objectsContext();
	if(page === undefined) page = parseInt($("#objects-page").val() || "0", 10);
	return callFeature(ctx.feature, "List", "GET", { object: ctx.object, page })
		.then(rs => showOn("#objects-list", rs));
}

function objectsNew() {
	let ctx = objectsContext();
	callFeature(ctx.feature, "Form", "GET", { object: ctx.object })
		.then(rs => showOn("#objects-form", rs));
}

function objectsEdit(id) {
	let ctx = objectsContext();
	callFeature(ctx.feature, "Form", "GET", { object: ctx.object, id })
		.then(rs => showOn("#objects-form", rs));
}

function objectsSave(el) {
	let ctx = objectsContext();
	let data = getFormDataFromElement(el);
	callFeature(ctx.feature, "Save", "POST", data)
		.then((rs) => {
			rs = objectsParse(rs);
			if(!rs.success) {
				showToast(rs.error, "Error", true);
				return;
			}
			showToast(rs.type == "insert" ? "Object inserted!" : "Object updated!", rs.type == "insert" ? "Inserted!" : "Data Saved", false);
			objectsLoadList(rs.type == "insert" ? 0 : undefined);
			objectsEdit(rs.id);
		})
		.catch(err => showToast(err.error || "could not save", "Error", true));
}

function objectsDelete(el) {
	let ctx = objectsContext();
	let data = getFormDataFromElement(el);
	if(!data || !data["magrathea_object_id"]) {
		showToast("Could not find the object's id", "Error", true);
		return;
	}
	if(!confirm("Are you sure you want to delete this object?")) return;
	callFeature(ctx.feature, "Delete", "POST", {
		magrathea_object: data["magrathea_object"],
		magrathea_object_id: data["magrathea_object_id"],
	})
		.then((rs) => {
			rs = objectsParse(rs);
			if(!rs.success) {
				showToast(rs.error || "could not delete", "Error", true);
				return;
			}
			showToast("Object removed!", "Deleted!", false);
			showOn("#objects-form", "");
			objectsLoadList();
		})
		.catch(err => showToast(err.error || "could not delete", "Error", true));
}
