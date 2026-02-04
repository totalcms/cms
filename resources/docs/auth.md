## Page Lock

This stack will lock a page. You can allow anyone who authenticates or also require that they be in a group. Another interesting feature is that you can also create a custom authentication collection. The default is the auth collection. That collection is required to be used to log into the admin dashboard. However, you can create your own for clients that can then be used to authenticate on your webpages. Users in these custom auth collections will not be allowed to log into the dashboard.

Unlike PageSafe, the user does get redirected to the centralized login page on the dashboard. However, after successful login, they should get sent back to the original page that they were attempting to access.

## Section Lock

This stack is like Stack Safe or Visilok. It will allow you to show/hide parts of the page based on the who is logged in or what groups they are assigned to. It has the same features as Page Lock in terms of a custom collection and groups. Although Section Lock currently only allows you to define a single group.

## User Data

This stack simply loads in the data for the currently logged in user. There are macro hints to make it easy for you to know how to insert that data onto the page.

SuperAdmin Access
If a user is inside of the default auth collection and is added to the admin group, that user is essentially a superadmin and will be able to access everything, even if you are using a custom auth collection.

## Customizing the login screen

You can customize the login screen through adding the following templates into the CMS. Check out the
[Whitelabel documentation](docs/whitelabel) for more information on how to do this.

* `whitelabel/login-above.twig` : Contents that will be added above the login form. This will replace the default content that
already exists.
* `whitelabel/login-below.twig` : Contents that will be added below the login form.

## Redirecting to a Custom Login Page

If you want to redirect users from the built-in login page to your own custom login page, you can use an Apache `.htaccess` redirect rule. This is useful when you want complete control over the login experience rather than just customizing the built-in login screen.

Add the following to your `.htaccess` file:

```apache
<If "%{REQUEST_METHOD} == 'GET'">
    Redirect 301 /rw_common/plugins/stacks/tcms/login /login
</If>
```

**Notes:**
- Replace `/rw_common/plugins/stacks/tcms` with your actual Total CMS installation path
- Replace `/login` with the path to your custom login page
- The `<If>` condition ensures only GET requests are redirected, allowing POST requests (form submissions) to still reach the original endpoint
- Your custom login page should still submit the login form to the Total CMS authentication endpoint

