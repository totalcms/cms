---
title: "Total CMS Studio"
description: "Total CMS Studio is a free Mac app that runs Total CMS sites on your computer. Make a demo site from a starter in minutes, bring in an existing Composer project, share a site with a client, and get it ready to upload."
related:
  - get-started/installation
  - site-builder/starters
  - mcp/connect
  - operations/deployment
  - operations/licenses
---

# Total CMS Studio

Total CMS Studio is a Mac app that runs Total CMS sites on your own computer. PHP, Composer, Node and a web server come built in, so there is nothing else to install or configure. Pick a starter and you have a working site with its admin in a few minutes. It's the fastest way to try Total CMS, build a demo for a client, or work on a site before it goes live.

[Download Total CMS Studio](https://totalcms.co/studio/download)

Studio needs macOS 14 (Sonoma) or later on a Mac with Apple silicon. It needs an internet connection to create a site, because it downloads Total CMS and the starter at that point.

## Your first site in minutes

1. Open the downloaded disk image and drag **Total CMS Studio** to your **Applications** folder, then open it from there.
2. Click **New Site…**.
3. Pick a starter. Starters marked "Coming soon" aren't available yet.
4. Choose a folder for the site. Pick an empty folder, or make one with **New Folder**. The site's files go straight into it. Studio starts you in `~/Sites`.
5. Give the site a name, then fill in the admin account: name, email and password.
6. Click **Create Site**.

Studio downloads Total CMS, installs the frontend packages, builds the CSS and JavaScript, and creates your admin account. When it's done, the site's homepage opens in your browser. **Open Admin** in Studio takes you to the admin, already set up and ready to sign in.

Each site gets its own address on your computer, such as `http://photo-test.localhost:8780`, with the admin at `/admin`. Sites on a `.localhost` address run with every Pro feature and need no license key. See [Development Domains](docs/operations/licenses#development-domains).

## Bring an existing site into Studio

If you already build Total CMS sites with Composer, Studio can run them for you.

1. Click **Add Existing…** and choose the project folder: the folder that holds `composer.json`.
2. Studio checks the folder, runs Composer install with its own PHP, and starts serving the site at its own `.localhost` address.

Your files stay where they are. Studio doesn't copy or move the project, and **Remove from Studio…** only takes the site out of Studio's list. It never deletes files.

Studio runs a site when:

- the folder is a Composer project whose `composer.json` requires `totalcms/cms`
- the site uses the standard root layout, with the front controller at `public/index.php`

Studio doesn't support these yet:

- **Zip installs.** A site installed from the zip download has no `composer.json` that requires `totalcms/cms`.
- **Stacks sites.** A site built with the Total CMS stacks isn't a Composer project.
- **The subpath layout.** A project with its front controller at `public/tcms/index.php`.

If Studio turns a folder away, the message says why. They are all listed under [Troubleshooting](#troubleshooting).

## Everyday use

Select a site in the sidebar to see what you can do with it.

- **Start Site** and **Stop Site** run and stop the site. A site you add starts running straight away. Stopped sites keep their place in the list.
- **Open in Browser** and **Open Admin** open the site and its admin.
- **Open in…** opens the project in an editor installed on your Mac, and Studio remembers the one you pick.
- **Show in Finder** shows the project folder, and **Show log** opens the site's log.
- **Rename…** changes the name Studio shows. The site's address stays the same.

### Watch CSS & JS

Turn on **Watch CSS & JS** and Studio rebuilds the site's CSS and JavaScript each time you save a file. It runs the `watch` script in the site's `package.json`, which every starter has. Sites without a `watch` script don't show the switch. When Studio builds a site for another reason, such as an update, it stops watching while it builds. Turn it back on when you're ready.

### Connect AI tools

**Connect AI tools** lets Claude Code, Cursor and VS Code work on the site. Studio creates an admin key that works only on Studio's server and only from your computer, and writes it into the project's `.mcp.json`, `.cursor/mcp.json` and `.vscode/mcp.json` under the name `totalcms-local`. Open the folder in one of those tools and it connects to the site. The site must be running for them to reach it.

The name `totalcms-local` keeps it apart from a `totalcms` connection to your live site. Studio also lists those files in the project's `.gitignore` so the key stays out of git. See [Connect an AI client](docs/mcp/connect) for how the key works.

### Let AI tools create sites

**Connect AI tools** works inside one site. This is different: it lets an AI tool manage Studio itself. Ask Claude Code, Codex, Claude Desktop, Cursor or VS Code to "make a portfolio demo site in Studio" and it can do it without you clicking through **New Site…**. It can:

- list the sites in Studio and the starters you can build from
- create a site from a starter, and open its homepage when it's done
- add an existing Composer project, with the same checks as **Add Existing…**

It can't start, stop, rename, share or remove a site. To work on a site's content, use **Connect AI tools**.

To set it up, open Settings (**Total CMS Studio › Settings…**) and find **AI tools**. Each AI tool has a row with one button:

- **Claude Code** and **Codex (ChatGPT)**: click **Copy prompt** and paste it into the tool. The prompt asks the tool to add Studio itself, and to tell you when to restart it. Claude Code adds Studio for your user, so it works in every folder. These two rows are always there, because Studio can't tell whether they're installed.
- **Cursor** and **VS Code**: click **Add to Cursor** or **Add to VS Code**. The app opens and asks you to confirm before it adds Studio.
- **Claude Desktop**: click **Add to Claude Desktop**. Studio adds itself to Claude Desktop's settings and leaves everything else in that file alone. Quit and reopen Claude Desktop to use it.

Cursor, VS Code and Claude Desktop only get a row when they're installed. For any other AI tool, open **Show setup for other tools** and copy the command or config it needs: the Claude Code command, JSON for Claude Desktop and Cursor, JSON for VS Code, and TOML for Codex. If the tool already has a config file, add the `totalcms-studio` entry under its existing `mcpServers` key (`servers` in VS Code) rather than replacing the file.

ChatGPT's chat can only connect to MCP servers on the internet, and Studio opens no network port, so it can't reach Studio. Use Codex, ChatGPT's coding agent, instead.

The setup points at the copy of Studio that's running. If you move Studio, set it up again. If Studio is still on its disk image or in Downloads, Settings asks you to move it to your Applications folder first, because the path wouldn't last.

You don't need to have Studio open. When an AI tool asks for something, Studio opens in the background if it isn't running. Creating a site takes a few minutes, the same as in the window, and the new site appears in the sidebar when it's done. Some AI tools stop waiting after a minute or so. The site is still being made, and it shows up in `list_sites` once it's done, so the tool should check again later instead of starting over. Studio makes one site at a time, so **New Site…** asks you to wait while an AI tool is creating one.

AI tools reach Studio through a private file on your Mac that only your user account can open. Studio doesn't open a network port for this, so no web page or other computer can reach it.

## Share a site

**Share** gives a running site a public link, so you can show it to a client before it's live. **Copy link** copies it, and **Stop sharing** ends it. Share links also end when you quit or restart Studio.

There are two kinds of link.

- **Quick link.** Without any setup, Studio opens a temporary link on `trycloudflare.com`. Anyone with the link can see the site, admin login included. Each share gets a new random address.
- **Your own domain.** If your domain is on Cloudflare, click **Connect Cloudflare…** in Settings (**Total CMS Studio › Settings…**) and pick the domain on the Cloudflare page that opens. Each site then shares on its own name on that domain, such as `photo-test.yourdomain.com`, and keeps the same name every time you share it. **Use another domain…** switches domains, and **Disconnect** goes back to quick links.

## Updates

### Studio

Studio checks for a new version once a day, and **Check for Updates…** in the app menu checks now. When an update is ready, **Restart to Update** installs it. Your sites stop and share links end while Studio restarts.

Studio can only update itself from a folder it can write to. Keep it in **Applications**, or another folder on your Mac or an external drive. It can't update while it runs straight from the downloaded disk image.

### Your sites

The **Maintenance** section of each site has three jobs.

- **Update Total CMS** updates the site's PHP packages with Composer, Total CMS included, then runs `tcms deploy` to clear caches and finish the update. See [Updates](docs/operations/updates).
- **Update frontend packages** updates the packages in `package.json` and rebuilds the CSS and JavaScript.
- **Prepare for upload** gets the folder ready to copy to your web host. It takes out the PHP development tools and builds the CSS and JavaScript. Then upload the folder as described in [Deployment](docs/operations/deployment). **Update Total CMS** puts the development tools back.

Studio runs one job on a site at a time. If you stop a job before it finishes, run it again before you use the site.

## Languages

Studio comes in English (US and UK), German, Spanish, Italian, Dutch and Polish, and follows your Mac's language.

To use Studio in a different language from the rest of your Mac, open System Settings › General › Language & Region › Applications, add Total CMS Studio and choose the language, then reopen Studio.

Starter descriptions and update notes come from totalcms.co, so they are always in English.

## Troubleshooting

Studio shows these messages in your Mac's language, and the tables list the English wording. Most problems come with a message that says what to do. The site log (**Show log**) has the details for anything that fails while Studio works on a site.

### Adding a site

| Message | What to do |
|---|---|
| That folder isn't a Composer project. Choose the folder that contains composer.json. | Pick the project's top folder, not `public/` or `tcms-data/`. Zip installs and Stacks sites have no `composer.json` and aren't supported yet. |
| That folder isn't a Total CMS project. Its composer.json doesn't require totalcms/cms. | The project doesn't install Total CMS through Composer. |
| This project uses the subpath layout (public/tcms/), which Studio doesn't support yet. | Studio only runs the root layout for now. |
| This project has no public/index.php, so there is nothing to serve. | Check that the site's front controller is at `public/index.php`. |
| That folder is already in Studio as "…". | The site is already in the sidebar under that name. |
| This version of Total CMS can't create the admin account from Studio yet. The setup wizard will ask for it when you open the site. | The site was created with an older Total CMS. Finish the setup wizard in the browser. |
| Studio is already creating a site. Try again when it's done. | Studio makes one site at a time. An AI tool may be creating one. Wait for it to finish. |

### Sharing

| Message | What to do |
|---|---|
| Cloudflare is limiting quick links right now. Try again in a few minutes. | Quick links are rate limited by Cloudflare. Wait, or connect your own domain. |
| Cloudflare didn't open the link in time. Try again. | Share again. |
| Studio couldn't reach Cloudflare. Check your internet connection and try again. | Check that your Mac is online. |
| Cloudflare didn't finish connecting. Try again, and pick your domain on the Cloudflare page. | The Cloudflare page closed before a domain was picked. |
| Your Cloudflare login has expired. Connect Cloudflare again in Settings. | Click **Connect Cloudflare…** in Settings. |
| Cloudflare doesn't have this site's tunnel any more. Share again and Studio will make a new one. | The tunnel was deleted in Cloudflare. Share again. |

### Updates and AI tools

| Message | What to do |
|---|---|
| Move Total CMS Studio to your Applications folder to get updates. | Studio is running from the disk image or a folder it can't write to. Move it to **Applications** and open it from there. |
| This update didn't pass Studio's security check, so Studio didn't install it. Download Studio again from totalcms.co. | [Download Studio again](https://totalcms.co/studio/download) and replace the copy in **Applications**. |
| This site's Total CMS is too old to connect AI tools. Use Update Total CMS first, then try again. | Run **Update Total CMS**, then **Connect AI tools** again. |
| Total CMS updated, but its cleanup step (tcms deploy) failed. The site log has the details. | The update is in place. Check the log, then run **Update Total CMS** again. |

### Studio itself

| Message | What to do |
|---|---|
| Studio couldn't find a free port near 8780 for its local web server. | Other apps are using the ports Studio looks for. Quit them and restart Studio. |
| The local web server didn't start. Details are in the server log. | Restart Studio. If it happens again, the server log says why. |
| Total CMS Studio is missing some of its built-in tools. Please reinstall the app. | [Download Studio again](https://totalcms.co/studio/download) and replace the copy in **Applications**. |
