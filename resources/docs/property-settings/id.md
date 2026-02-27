---
title: "ID"
description: "Auto-generate object IDs in Total CMS from field values using autogen templates with variables like uuid, date components, and custom formats."
---

# ID

For the ID field, you can use the following setting to autogenerate the id from one or
multiple fields. You can use standard Javascript string interpolation to inject field
values. You simply need to use the property name in the autogen value. There are also
a few special variables that you can use below.


```json
{
	"autogen" : "${title}-${now}"
}
```

## Special Autogen Variables

* **now** - Current timestamp in milliseconds (e.g., 1692123456789)
* **timestamp** - Current date/time in ISO format without colons/dashes (e.g., 20230815T143056)
* **uuid** - Real UUID v4 format (e.g., 550e8400-e29b-41d4-a716-446655440000)
* **uid** - Short random alphanumeric string (e.g., a4k7m2x)
* **oid** - Object ID counter (increments with each new object in collection)
* **oid-00000** - Zero-padded Object ID (e.g., oid-00001, oid-12345)
* **currentyear** - Full 4-digit year (e.g., 2025)
* **currentyear2** - 2-digit year (e.g., 25)
* **currentmonth** - Zero-padded month 01-12 (e.g., 01, 12)
* **currentday** - Zero-padded day 01-31 (e.g., 01, 31)

## Autogen Special Variables Examples

**Using timestamp for date-based IDs:**
```json
{
	"autogen" : "${title}-${timestamp}"
}
```
Generates: `my-post-20230815T143056`

**Using now for unique numeric IDs:**
```json
{
	"autogen" : "item-${now}"
}
```
Generates: `item-1692123456789`

**Using uuid for unique IDs:**
```json
{
	"autogen" : "${title}-${uuid}"
}
```
Generates: `my-post-550e8400-e29b-41d4-a716-446655440000`

**Using uid for short random IDs:**
```json
{
	"autogen" : "${title}-${uid}"
}
```
Generates: `my-post-a4k7m2x`

## OID (Object ID) Examples

The `oid` placeholder provides automatic sequential numbering based on the collection's object count:

```json
{
	"autogen" : "item-${oid}"
}
```
Generates: `item-1`, `item-2`, `item-3`, etc.

**Zero-padded OID:**
```json
{
	"autogen" : "product-${oid-00000}"
}
```
Generates: `product-00001`, `product-00002`, `product-00003`, etc.

**Different padding lengths:**
```json
{
	"autogen" : "${oid-000}"
}
```
Generates: `001`, `002`, `003`, etc.

**Combined with other placeholders:**
```json
{
	"autogen" : "${title}-${oid-00}"
}
```
Generates: `my-title-01`, `another-title-02`, etc.

The OID counter automatically increments each time a new object is created in the collection, ensuring unique sequential IDs.

## Date-Based ID Examples

The date variables are useful for creating time-based IDs that sort chronologically:

**Year-based membership IDs:**
```json
{
	"autogen" : "${currentyear}-${oid-000000}"
}
```
Generates: `2025-000001`, `2025-000002`, etc.

**Short date format:**
```json
{
	"autogen" : "${currentyear2}${currentmonth}${currentday}-${oid-000}"
}
```
Generates: `251107-001`, `251107-002`, etc. (YY-MM-DD-ID format)

**Invoice-style IDs:**
```json
{
	"autogen" : "INV-${currentyear}${currentmonth}-${oid-0000}"
}
```
Generates: `INV-202511-0001`, `INV-202511-0002`, etc.

**Membership cards:**
```json
{
	"autogen" : "26-${currentyear2}-${oid-000000}"
}
```
Generates: `26-25-000001`, `26-25-000002`, etc.
