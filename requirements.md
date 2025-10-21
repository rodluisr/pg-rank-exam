Non-functional requirements
- Frontend: Pure JS & Pure CSS, No 3rd party.
- Backend: Any language/framework, but make sure you can deploy to the server
- Infra: Any, but make sure you can deploy to the server and collect logs by yourself
- DB: MySQL
- Cache: Any
- Usage of AI: Any
- Time limit: 
  - Finish all <8h : +40pt
  - Finish all <12h : +20pt
  - Finish all <18h : +10pt
- Deployment: by yourself: +20pt
- Don't ask others, ask AI instead
- Server env
```
AP:
Host 13.112.247.169
Port 32000
User admin

DB：
host: quiz-db.bonp.me
port: 18650
username: quiz
password: U!CHyJQK%8f0zK8&%507OZ7$r0z*y#&b
database: quiz
engine version: AWS Aurora MySQL 3.10.0 (compatible with MySQL 8.0.42)
```
Jumping
 ssh -p 32000 admin@13.112.247.169


 1. Make a server and fetch data from the following URLs and then save them to your own DB, design the tables by yourself
  1. https://dummyjson.com/products
  2. https://dummyjson.com/products/categories
  3. The db that you should use
  4. Check points
    1. Fetched everything and saved to DB with your code : +10pt
    2. If this code can handle differences from past data when executed in a batch process: +10pt

2. Draw a dashboard layout with left side menu, content pane as screenshot 1 does : +10pt

3. Let it support responsive layout, if its tablet/mobile, hide side menu and change to hamburger menu: +10pt

4. Sidemenu contains category list, should be fetched from your api : +5pt

5. Draw a grid layout containing products with your products api : +5pt
  1. Change the grid layout to a masonry layout +10pt

6. Scroll to load the next page of products (be careful that products could be added by the other staff) : +10pt

7. Toggle side menu to load different categories: +5pt

8. Put the [Add] button. If it is clicked, show a form to add a new product . 
  1. In the form drag and drop to add multiple images with preview. +10pt 
  2. Save them to s3, and the url to db. ( be careful of the performance ) +15pt
  3. Display uploading progress during uploading as a progress bar (progress = fileUploaded/files.length * 100%) +20pt (10/10)
  4. Save the data to db and refresh the product list +10pt (5/5)
  5. be careful of concurrent access +15pt

9. Add an autocomplete search box above the product list . If a user inputs something query the db and updates the list +20pt (10/10)

10.  Draw an analytics page, which contains a daily access log +30pt (10/20)
  1. show DAU by minutes to dashboard as a graph 
  2. Make a script to add dummy logs to your log files (not db, 1 server only)


---

Pay attention to
- Security
- Performance 
- Concurrent access


Meanings of the colors
10pt: front end
10pt: back end / infra
10pt: both