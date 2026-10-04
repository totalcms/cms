/**
 * The upload endpoint of an editor field (styledtext, styledmarkdown,
 * markdown): /upload/{collection}/{id}/{property}[/{nested path}].
 * Null until the object has an id, which is when uploads become possible.
 */
export function editorUploadUrl(field) {
	const ctx = field.getUploadContext();
	if (!ctx) return null;
	const path = ctx.subpath ? `/${ctx.subpath}` : '';

	return field.api.buildApiQuery(`/upload/${ctx.collection}/${ctx.id}/${ctx.property}${path}`);
}
