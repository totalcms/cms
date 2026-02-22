## Search Scope

This search function will be to search through a single collection. The results can be displayed with one of the Loop stacks. This is similar to how search works with blog in T1.

The search query can search through any property that has been added to the Index. These are configured in the schema.

The search should also search through all attributes of a property. For example, if an image is a part of the index, the search will also look at all of the exif data and other attributes that are associated with the image.

I know that we would love to have a full site search. I have plans for this, but it will have to be pushed off into the future. And that will definitely be a Pro-only feature.

## Search Syntax

This is where search in T3 is getting a major upgrade!

#### Case Insensitive Search

All searches will be case insensitive.

#### Implicit AND

If you search for multiple terms and they will be searched for separately. For example, `Brazil coffee` will search for `Brazil` and `coffee` separately. The terms can be used in 2 completely separate properties as well.

You can also explicitly use the `and` in your query and it will result in the same behavior. For example, `Brazil coffee` will produce the same exact results as `Brazil and coffee`.

#### Searching multiple words

But let's say that you wanted to search for the exact term `Brazil coffee`. If you encapsulate that inside quotes, then it will be. You can even combine this with other terms to get the implicit AND logic. For example, `"Brazil coffee" espresso` will search for `Brazil coffee` as a combined term and also search for `espresso` as its own query.

#### Explicit OR

What if you wanted to have OR logic inside your query. You can do that by explicitly using it inside your query. For example, `Brazil or coffee` will result in searching for either term inside of your collection.

Now let's say that you have the following search query: `Brazil and coffee or espresso`. The search algorithm is not that clever at this time. This search will effectively result in the following search happening: `Brazil or coffee or espresso`.

#### Searching a specific property

You can search within a specific property like the following: `title:Brazil`. This will then search just the title property of all objects for that term.

You can also use this in conjunction with all of the above AND and OR logic as well. Example: `title:Brazil or title:coffee or summary:coffee`.

## Prioritizing Search Results

In search, it is common that we will want to prioritize the results based on where a particular term is found. For example, it might a result should be ranked higher in the results if the search term was found in the title vs the summary.

When you are setting up the search results, you will be able to define a list of properties that you would like to be prioritized. The order of this list will determine the priority. You should only define that properties that you deem special to be at the top.

Adding this step does add additional processing load to generating the search. If you have a very large data set, you may want to test the performance of your search results with and without this enabled. Also try to keep the number of priority properties to a minimum.