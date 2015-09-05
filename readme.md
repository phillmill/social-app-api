<h1>Social App API</h1>

This API is designed and intended to be used for Android (iOS to come soon) apps that are focused on the social aspect. It can be used as a starting point for authenticating users, allowing users to search for and add friends.

Users will be notified when they have a friend request via Push notifications. 

It will authenticate with both Facebook and standalone manually created users. So your app can have the option to sign in with facebook OR create an account.

<h2>Before you get started you will need to:</h2>
- Create a Facebook app, and retrieve it's APP ID and Secret.
- Create a database that will be used for this app
- This API requires a registration ID as a pamarater to some endpoints for push notifications. So you will need to have that configured before using this API.
- Optionally, you may retrieve a Google Places API key if you wish to take advantage of this API's geolocation features.

<h2>Installation:</h2>
First, create the database that will be used for the Social App API. Simply import the sql file found in databases/social-app-api.sql.

Now you can upload the api/ directory to your server of choice and configure it. To do that you will edit config.ini that looks like what you see below.

All of the configurations should be relatively self explanatory. I would recommend you change the md5_salt to something else, unique to your app.

```
[globals]

DEBUG=3

; Database info
dbname="YOUR_DB_NAME"
dbuser="YOUR_DB_USER"
dbpass="YOUR_DB_PASS"

; The salt that gets sprinkled in to md5 encryptions
md5_salt="3498hjf32kr"

; Your API key for google places API
google_places_api_key="YOUR_API_KEY"

; Cache time
cache_time=900

; The fallback photo if a profile photo could not be found for a user
default_user_photo="http://yourdomain.com/default-user-photo.png"

app_name="Super Cool Fun App"

; Your facebook app information
facebook_app_id="YOUR_FACEBOOK_APP_ID"
facebook_app_secret="YOUR_FACEBOOK_APP_SECRET"

; SMTP / Email information for sending emails. This framework uses PHPMailer
smtp_host="localhost"
smtp_port=25
email_from='email@yourdomain.com'
email_from_name='Your Name'

; The referer that will be passed along with CURL requests
curl_referer="http://yourdomain.com"
```

<h2>Usage</h2>

<h3></h3>

<p>When possible, three different header arguments should always be passed along with each API request:</p>
<table>
  <tr>
    <th align="left">Header Argument</th>
    <th align="left">Description</th>
  </tr>
  <tr>
    <td align="left">Token</td>
    <td>Whenever you sign in a user, the API will return a Token. I recommend you save this to the device and send it back to API on every request. The API uses it to authenticate and retrieve information about the user.</td>
  </tr>
  <tr>
    <td align="left">FacebookAccessToken</td>
    <td>When signing in a user on a device with Faceook, you need to send the Facebook Session ID back to the API using this header in each request - for the same reasons explained above.</td>
  </tr>
  <tr>
    <td align="left">RegistrationID</td>
    <td>You must provide the registration ID on every API request for the ability to use Push Notifications.</td>
  </tr>
</table>

All end points return a JSON response, in a format like the below. When the status is below 0, there is a problem. The problem will always be explained in a field named "status_explanation"

<h3>Successfull Response</h3>
```json
{
  "status": 1,
  "status_explanation": "Success.",
  "token": "01707a57f00675a9db66a0fbb83ec4fc"
}
```

<h3>Errornous Response</h3>
```json
{
  "status": -1,
  "status_explanation": "Insufficient data provided."
}
```

<h3>API Methods</h3>

<p>[Incomplete] more documentation coming. Any endpoint that is a link has documentation.</p>

- <a href="#user-sign-up">/user/sign-up</a>
- <a href="#user-fb-ping">/user/fb-ping</a>
- <a href="#user-sign-in">/user/sign-in</a>
- /user/get-info
- /user/edit-information
- /user/data-update
- /user/add-friend
- /user/accept-friend
- /user/ignore-friend
- /user/get-friends
- /user/reset-pass
- /find-users
- /user/@id
- /achievements BETA

<p id="user-sign-up"><strong>POST /user/sign-up</strong><br />
Signs up a user</p>

<table>
  <tr>
    <th align="left">Parameter Name</th>
    <th align="left">Description</th>
  </tr>
  <tr>
    <td align="left">facebook_id</td>
    <td>The user's Facebook ID</td>
  </tr>
  <tr>
    <td align="left">email</td>
    <td>The user's email</td>
  </tr>
  <tr>
    <td align="left">password</td>
    <td>The user's chosen password</td>
  </tr>
  <tr>
    <td align="left">registration_id</td>
    <td>The user's registration ID</td>
  </tr>
</table>

<br />

<p id="user-fb-ping"><strong>POST /user/fb-ping</strong><br />
With a Facebook ID, this endpoint figures out whether the facebook user is in your datadase or not, then signs up or signs in accordingly</p>

<table>
  <tr>
    <th align="left">Parameter Name</th>
    <th align="left">Description</th>
  </tr>
  <tr>
    <td align="left">facebook_id</td>
    <td>The user's Facebook ID</td>
  </tr>
  <tr>
    <td align="left">registration_id</td>
    <td>The user's registration ID</td>
  </tr>
</table>

<br />

<p id="user-sign-in"><strong>POST /user/sign-in</strong><br />
With a Facebook ID, this endpoint figures out whether the facebook user is in your datadase or not, then signs up or signs in accordingly</p>

<strong>PLEASE READ</strong> This endpoint will take two forms of signing the user in. Either a username and password OR a hash token. The hash token should be provided to the API via a header named "Token". The reason for this is because most API calls rely on the token header to not only authenticate, but to get information about the user.

<table>
  <tr>
    <th align="left">Parameter Name</th>
    <th align="left">Description</th>
  </tr>
  <tr>
    <td align="left">email</td>
    <td>The user's email</td>
  </tr>
  <tr>
    <td align="left">password</td>
    <td>The user's password</td>
  </tr>
</table>

<h2>Example front end implementation</h2>

If you are accustomed to building Cordova / Phonegap applications such as I, you may use jQuery to submit your network requests. Here is an example of signing in a user using jQuery:

```javascript
$.ajax({
	url: 'http://yourdomain.com/api/user/sign-in',
	cache: false,
	localCache: false,
	isCacheValid : false,
	timeout: 5000,
	dataType: 'json',
	type: 'POST',
	beforeSend: function(request) {
		// Send token as header
	  request.setRequestHeader("Token", get_user_token());

	  // Send Registration as header
	  request.setRequestHeader("RegistrationID", get_registration_id());

	  // Send Facebook session (if there is one)
	  if( window.cordova ) {
	    request.setRequestHeader("FacebookAccessToken", get_facebook_session());
	  }
	},
	complete: function(){},
	error: function(jqXHR, textStatus) {
	   alert('We can\'t process your request. Please try again.');
	},
	success: function(response) {
		if(response && response.status > 0) {
      if(app.debug) console.log('Successful auto sign in');
      app.signed_in = true;
      // redirect somewhere
    } else {
      if(app.debug) console.log('Could not auto sign in');
    }
	}
});
```
