## Total CMS Adapter

### Form Helpers

```
cms.formDefinitions(string $property, string $collection, ?string $id): array
cms.getData(string $key): mixed
cms.storeData(string $key, mixed $value): void
cms.clearStorage(): void
```

### Collection Data

```
cms.collection(string $collection): array
cms.objects(string $collection): array
```

### Object Data

```
cms.object(string $collection, string $id): array
```


### Property Data

```
cms.property(string $collection, string $property): array
cms.data(string $collection, string $id, string $property): mixed
cms.text(string $id, string $collection = 'text', string $property = 'text'): string
cms.styledtext(string $id, string $collection = 'styledtext', string $property = 'styledtext'): string
cms.depot(string $id, string $collection = 'depot', string $property = 'files'): array
```

### Image Data

```
cms.imagePath(?string $id, array $options = [], string $collection = 'image', string $property = 'image'): string
cms.alt(string $id, string $collection = 'image', string $property = 'image'): string
```
