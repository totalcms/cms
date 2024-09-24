## Page Lock

This stack will lock a page. You can allow anyone who authenticates or also require that they be in a group. Another interesting feature is that you can also create a custom authentication collection. The default is the auth collection. That collection is required to be used to log into the admin dashboard. However, you can create your own for clients that can then be used to authenticate on your webpages. Users in these custom auth collections will not be allowed to log into the dashboard.

Unlike PageSafe, the user does get redirected to the centralized login page on the dashboard. However, after successful login, they should get sent back to the original page that they were attempting to access.

## Section Lock

This stack is like Stack Safe or Visilok. It will allow you to show/hide parts of the page based on the who is logged in or what groups they are assigned to. It has the same features as Page Lock in terms of a custom collection and groups. Although Section Lock currently only allows you to define a single group.

## User Data

This stack simply loads in the data for the currently logged in user. There are macro hints to make it easy for you to know how to insert that data onto the page.

SuperAdmin Access
If a user is inside of the default auth collection and is added to the admin group, that user is essentially a superadmin and will be able to access everything, even if you are using a custom auth collection.