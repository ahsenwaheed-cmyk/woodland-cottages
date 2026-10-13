# Getting this on GitHub

Claude could not push for you: this session is only allowed to touch
repositories you have explicitly attached to it, and a brand-new one is not
attached. Two ways round it — the first is faster.

## 1. Let Claude do it (about a minute of your time)

1. Go to github.com and create a new **private** repository called
   `woodland-cottages`. Do not add a README, a .gitignore or a licence —
   it must be empty.
2. In the Claude desktop app, add that repository to this conversation's
   sources (the GitHub connection, "add repository").
3. Tell Claude it is done. Claude pushes everything and deploys to Vercel.

## 2. Do it yourself

Unzip this folder somewhere sensible, then double-click **PUSH-ME.command**.
It needs the GitHub CLI once:

    brew install gh
    gh auth login

Or by hand, in Terminal, inside this folder:

    git init -b main
    git add -A
    git commit -m "Woodland Cottages"
    git remote add origin https://github.com/<your-username>/woodland-cottages.git
    git push -u origin main

Then tell Claude the repository name and it will link it to Vercel and deploy.

## What must happen after the first deploy

The site works the moment it is deployed. The booking form does not, until
one environment variable exists.

Vercel → the project → Settings → Environment Variables → add:

    RESEND_API_KEY = (from resend.com → API Keys)

to **Production and Preview**, then redeploy. Until then the form hands guests
to WhatsApp with the request already written out, which is a working route —
not a broken one.

Everything else is in README.md.
