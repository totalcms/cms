# Factory Twig Extension

<https://fakerphp.github.io/formatters/>

### Text

```
word()
words($words = 3, $asString = false)
sentence($words = 6, $exact = true)
sentences($sentences = 3, $asString = false)
paragraph($sentencses = 3, $exact = true)
paragraphs($paragraphs = 3, $asString = false)
text($maxChars = 200)

realText($maxNbChars = 200, $indexSize = 2)
realTextBetween($minNbChars = 160, $maxNbChars = 200, $indexSize = 2)
```

### Images

```
factory.imageUrl(int $width = 640, int $height = 480): string
```

### Tags

```
factory.tags(int $min = 0, int $max = 5): array
```


### Person

```
title($gender = null|'male'|'female')     // 'Ms.'
titleMale()                               // 'Mr.'
titleFemale()                             // 'Ms.'
suffix()                                  // 'Jr.'
name($gender = null|'male'|'female')      // 'Dr. Zane Stroman'
firstName($gender = null|'male'|'female') // 'Maynard'
firstNameMale()                           // 'Maynard'
firstNameFemale()                         // 'Rachel'
lastName()                                // 'Zulauf'
```

### Address

```
cityPrefix()                       // 'Lake'
secondaryAddress()                 // 'Suite 961'
state()                            // 'NewMexico'
stateAbbr()                        // 'OH'
citySuffix()                       // 'borough'
streetSuffix()                     // 'Keys'
buildingNumber()                   // '484'
city()                             // 'West Judge'
streetName()                       // 'Keegan Trail'
streetAddress()                    // '439 Karley Loaf Suite 897'
postcode()                         // '17916'
address()                          // '8888 Cummings Vista Apt. 101, Susanbury, NY 95473'
country()                          // 'Falkland Islands (Malvinas)'
latitude($min = -90, $max = 90)    // 77.147489
longitude($min = -180, $max = 180) // 86.211205
```

### Phone Numbers

```
phoneNumber()              // '827-986-5852'
phoneNumberWithExtension() // '201-886-0269 x3767'
tollFreePhoneNumber()      // '(888) 937-7238'
e164PhoneNumber()          // '+27113456789'
```

### Company

```
catchPhrase()   // 'Monitored regional contingency'
bs()            // 'e-enable robust architectures'
company()       // 'Bogan-Treutel'
companySuffix() // 'and Sons'
jobTitle()      // 'Cashier'
```
