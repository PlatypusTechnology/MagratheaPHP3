# Admin: Objects

**Files:** `src/Admin/ObjectsExplorer.php`, `src/Admin/ModelFields.php`, `src/Admin/Features/Objects/AdminFeatureObjects.php`
**Namespace:** `Magrathea\Admin`

The admin's Objects area (since 3.0): a CRUD for every model of the project, with no admin class to write. It
lists the models, and each model's page shows its rows, its structure and a form to create, edit and delete
its objects. It's the first section of the menu, "Objects".

The logic is `ObjectsExplorer`, which returns arrays (tests cover it with fakes). The feature,
`Features\Objects\AdminFeatureObjects`, only renders them. The form fields come from `ModelFields`, which
[`AdminCrudObject`](admin-features.md) uses too.

---

## On by default

`Admin::SetFeatures()` loads it (`LoadObjects()`), so `StartDefault()` and every `Admin` subclass that calls
`parent::SetFeatures()` has it. To take it out:

```php
public function SetFeatures() {
    parent::SetFeatures();
    $this->RemoveFeature("AdminFeatureObjects");
}
```

`Admin::RemoveFeature($key)` removes any feature by its `featureId`, along with its menu items. A key that
isn't registered is ignored.

`Admin::AddMagratheaMenu()` adds the section ("Objects" title, then the item) after the
top items (Version, App Configuration) and before Setup, only while the
feature is registered.

---

## Which models

The models are the ones [`SchemaCheck::FindModels()`](../database/schema.md) finds in the code folders
(`MagratheaPHP::AddCodeFolder()`): the concrete `MagratheaModel` subclasses, and only the subclass when a
generated base class and its subclass are both found. It also lists the models' load warnings.

- **The framework's own models aren't listed.** That means any class in the `Magrathea\` namespace
  (`ObjectsExplorer::IsFramework()`): admin users, user logs, app config. Users and App Configuration have
  their own pages.
- **Only listed models can be read or written.** The model's class comes from the query string
  (`?object=App\Article\Article`). `ObjectsExplorer::Model()` compares it (case-insensitive) with the list,
  and throws `MagratheaModelException` with code 404 for anything else, before any class is instantiated.
- **Models only, not raw tables.** Writes go through the model (`Insert()`, `Update()`, `Delete()`), so its
  types, its uuid PK and its timestamps behave as in your code. To change a table that has no model, use
  Database → Queries.

There's no setting to hide one of your own models, or one of their fields. If you need that, remove the
feature and write an [`AdminCrudObject`](admin-features.md) for the models you want to show.

---

## Pages

Everything is under `?magrathea_feature=AdminFeatureObjects`.

| Page | URL | Shows |
|---|---|---|
| Models | (no `object`) | each model: name, class, table, field count, approximate rows (`Schema::Tables()`), or "no table" |
| A model | `&object=<class>` | its rows, a "New" button, and its structure |
| An object | `&object=<class>&id=<id>` | the model's page, with that object's form open |

The model's page shows:

- **The list**: a page of 25 rows, newest PK first (`ObjectsExplorer::Page()`), with every `$dbValues` field,
  plus `created_at`/`updated_at` when the table has them. Values longer than 80 characters are cut with "…"
  (`Cell()`). Each row has an "Edit" link. Long lists get Previous/Next buttons.
- **The form**, above the list. It opens with "New" or "Edit".
- **The structure**: each field with its type, the PK and the relations (`$relations["external"]`), the
  model's `SchemaCheck::CheckModel()` findings, and a link to the table in Database → Explorer. It's read-only:
  to change the structure, change the model and its table.

Actions (AJAX, `magrathea_feature_action`): `List` and `Form` are GET. `Save` and `Delete` are POST only: a GET
gets 405, and the CSRF check applies to every admin POST. Both answer JSON (`{"success", "type", "id"}`, or
`{"success": false, "error"}`). Saves and deletes go to the user logs (`AdminManager::Log()`, as
"Objects insert Article", "Objects update Article", "Objects delete Article").

---

## The form

`ModelFields::Fields($object, $isNew)` builds it from `$dbValues`, PK first, without the timestamps:

| Type | Element |
|---|---|
| `boolean` | switch |
| `text` | textarea (full width) |
| `int` | number (`step="1"`) |
| `float`, `decimal` | number (`step="any"`) |
| `date` | date |
| `datetime` | text, `YYYY-MM-DD HH:MM:SS` |
| `string`, `uuid` | text |
| a relation | a select of the related objects (their `Ref()`), with "(none)"; a text input for the id when the related table has more than 100 rows (`ModelFields::RELATION_OPTIONS_LIMIT`) |

The PK is disabled when the database or the model generates it (`int`, `uuid`). Any other PK (a string slug)
can be typed on a new object, and is disabled after that.

**Saving** (`ObjectsExplorer::Save($class, $id, $post)`):

- `$id` is the PK the object was loaded with, posted as `magrathea_object_id`. When it's empty, the object is
  new: it's inserted with `Insert()`, or with `InsertWithPk()` when the PK isn't generated (an empty PK then
  throws, code 400). Otherwise the object is loaded and updated with `Update()`.
- Only the model's `$dbValues` fields are assigned (`ModelFields::Values()`). The PK, `created_at`,
  `updated_at` and anything else posted (the CSRF token, `dbTable`…) are ignored, and so are arrays.
- An unchecked switch, which a form doesn't post, is `false`. On any field that isn't `string` or `text`, an
  empty value is `null`.
- The model's exceptions and the database's errors come back as `{"success": false, "error"}` and show in a
  toast.

---

## What only a real stack shows

The tests use fake models, a fake `SchemaCheck` and a recording `Database`. Check these in a browser on a real
database: the list's paging on a large table, the relation selects, the date and switch inputs, and saving
string and uuid PKs.
