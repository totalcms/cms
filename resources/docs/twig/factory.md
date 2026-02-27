---
title: "Factory Twig Extension"
description: "Generate realistic test data in Total CMS Twig templates using Faker-based factory functions for names, addresses, text, dates, and more."
---

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

styledtext($minParagraphs = 3, $maxParagraphs = 6, $includeLists = true, $includeHeadings = false)
```

### Images

```
imageUrl($width = 640, $height = 480): string
image($width = 640, $height = 480): string
imageBlur($width = 640, $height = 480, $blur = 10): string
imageBW($width = 640, $height = 480): string
imageBWBlur($width = 640, $height = 480, $blur = 10): string
imageText($width = 640, $height = 480, $textSize = 200, $bgColor = 'f8f8f8', $textColor = null, $text = null): string
imageShapes($width = 640, $height = 480, $bgColor = 'f8f8f8'): string
```

### Gallery

```
gallery($count = 3, $width = 640, $height = 480): string
galleryBlur($count = 3, $width = 640, $height = 480, $blur = 10): string
galleryBW($count = 3, $width = 640, $height = 480): string
galleryBWBlur($count = 3, $width = 640, $height = 480, $blur = 10): string
galleryText($count = 3, $width = 640, $height = 480, $textSize = 200, $bgColor = 'f8f8f8', $textColor = null, $text = null): string
galleryShapes($count = 3, $width = 640, $height = 480, $bgColor = 'f8f8f8'): string
```


### Tags

```
factory.tags($min = 0, $max = 4, array $choices = []): array
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

### Internet

```
email()                           // 'tkshlerin@collins.com'
safeEmail()                       // 'king.alford@example.org'
freeEmail()                       // 'bradley72@gmail.com'
companyEmail()                    // 'russel.durward@gibson.info'
freeEmailDomain()                 // 'gmail.com'
safeEmailDomain()                 // 'example.org'
userName()                        // 'wade.walter'
password($minLength = 6, $maxLength = 20) // 'fY4f*Zd5'
domainName()                      // 'wolff.info'
domainWord()                      // 'feeney'
tld()                             // 'biz'
url()                             // 'http://www.skilesmccullough.org/'
slug($nbWords = 6, $variableNbWords = true) // 'aut-repudiandae-ut-nam-assumenda-et'
ipv4()                            // '109.133.32.252'
ipv6()                            // 'e48e:c003:f51e:ac71:2b55:6e67:3b7f:f93f'
localIpv4()                       // '192.168.0.25'
macAddress()                      // '01:02:03:04:05:06'
userAgent()                       // 'Mozilla/5.0 (Windows CE) AppleWebKit/5350...'
```

### Date and Time

```
unixTime($max = 'now')            // 1502284496
dateTime($max = 'now')            // DateTime('1977-04-15 05:40:10')
dateTimeAD($max = 'now')          // DateTime('1553-01-19 12:44:43')
iso8601($max = 'now')             // '1972-11-14T07:44:50+0000'
date($format = 'Y-m-d', $max = 'now') // '1979-06-09'
time($format = 'H:i:s', $max = 'now') // '20:49:42'
dateTimeBetween($startDate = '-30 years', $endDate = 'now') // DateTime
dateTimeInInterval($date = '-30 years', $interval = '+5 days') // DateTime
dateTimeThisCentury($max = 'now') // DateTime
dateTimeThisDecade($max = 'now')  // DateTime
dateTimeThisYear($max = 'now')    // DateTime
dateTimeThisMonth($max = 'now')   // DateTime
amPm($max = 'now')                // 'pm'
dayOfMonth($max = 'now')          // '04'
dayOfWeek($max = 'now')           // 'Friday'
month($max = 'now')               // '06'
monthName($max = 'now')           // 'January'
year($max = 'now')                // '1999'
century()                         // 'VI'
timezone()                        // 'Europe/Paris'
```

### Numbers

```
randomNumber($nbDigits = null, $strict = false) // 79907610
randomFloat($nbMaxDecimals = null, $min = 0, $max = null) // 48.8932
numberBetween($min = 1000, $max = 9000) // 8567
randomDigit()                     // 7
randomDigitNotNull()              // 5
randomLetter()                    // 'b'
randomElements($array = ['a','b','c'], $count = 1, $allowDuplicates = false) // ['c']
randomElement($array = ['a','b','c']) // 'b'
numerify($string = '###')         // '609'
lexify($string = '????')          // 'wgts'
bothify($string = '## ??')        // '42 jz'
asciify($string = '****')         // '@rg('
regexify($string = '[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,4}') // 'SM0@P7.CO'
```

### Lorem Ipsum

```
word()                            // 'aut'
words($nb = 3, $asText = false)   // ['porro', 'sed', 'magni'] or 'porro sed magni'
sentence($nbWords = 6, $variableNbWords = true) // 'Sit vitae voluptas sint non voluptates.'
sentences($nb = 3, $asText = false) // Array or string of sentences  
paragraph($nbSentences = 3, $variableNbSentences = true) // 'Ut ab voluptas...'
paragraphs($nb = 3, $asText = false) // Array or string of paragraphs
text($maxNbChars = 200)           // 'Fuga totam reiciendis qui architecto...'
```

### Miscellaneous

```
boolean($chanceOfGettingTrue = 50) // true
md5()                             // 'de99a620c50f2990e87144735cd357e7'
sha1()                            // 'f08e7f04ca1a413807ebc47551a40a20a0b4de5c'
sha256()                          // 'b5d86317c2a144cd04d0d7c03b2b02666fcd5c20a0b4de5c'
locale()                          // 'pt_BR'
countryCode()                     // 'UK'
languageCode()                    // 'ru'
currencyCode()                    // 'EUR'
emoji()                           // '😁'
```

### Color

```
hexColor()                        // '#fa3cc2'
rgbColor()                        // '0,255,122'
rgbColorAsArray()                 // [0,255,122]
rgbCssColor()                     // 'rgb(0,255,122)'
safeHexColor()                    // '#ff6600'
safeColorName()                   // 'fuchsia'
colorName()                       // 'Gainsbor'
hslColor()                        // '340,50,20'
hslColorAsArray()                 // [340,50,20]
```
