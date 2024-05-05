## Total CMS Adapter

### Form Helpers

```
totalcms.formDefinitions(string $property, string $collection, ?string $id): array
totalcms.getData(string $key): mixed
totalcms.storeData(string $key, mixed $value): void
totalcms.clearStorage(): void
```

### Collection Data

```
totalcms.collection(string $collection): array
totalcms.objects(string $collection): array
```

### Object Data

```
totalcms.object(string $collection, string $id): array
```


### Property Data

```
totalcms.property(string $collection, string $property): array
totalcms.data(string $collection, string $id, string $property): mixed
totalcms.text(string $id, string $collection = 'text', string $property = 'text'): string
totalcms.styledtext(string $id, string $collection = 'styledtext', string $property = 'styledtext'): string
totalcms.depot(string $id, string $collection = 'depot', string $property = 'files'): array
```

### Image Data

```
totalcms.image(?string $id, array $options = [], string $collection = 'image', string $property = 'image'): string
totalcms.alt(string $id, string $collection = 'image', string $property = 'image'): string
```
