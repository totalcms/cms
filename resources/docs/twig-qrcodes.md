# QR Code Twig Extension


```
qr.url($url)
qr.text($text)
qr.tel($tel)
qr.gps($latitude, $longitude)
qr.sms($telephone, $message)
qr.wifi($auth, $ssid, $password, $hidden)
qr.mailto($email, $subject = '', $body = '')
qr.event({
	title    : "My Event",
	desc     : "This is my event",
	location : "The internet",
	start    : "2024-09-25T12:00:00+00:00",
	end      : "2024-09-25T14:00:00+00:00",
})
qr.vcf({
	first   : "Joe",
	last    : "Workman",
	company : "Aspect Services, LLC",
	street  : "123 Main St",
	city    : "Weaver's Cove",
	state   : "CA",
	zip     : "12345",
	phone   : "123-456-7890",
	mobile  : "123-456-7890",
	email   : "support@company.com",
	website : "https://www.weavers.space/",
})
```
