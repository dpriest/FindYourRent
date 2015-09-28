#!/usr/bin/env python
# -*- coding: utf-8 -*-  
import requests, bs4, re, MySQLdb, time, datetime, sys, urlparse

root_url = 'http://hz.58.com'
list_url = root_url + '/xiaoqu/'
db = MySQLdb.connect("localhost","root","online","tool" )
cursor = db.cursor()

def get_area_info():
    response = requests.get(list_url)
    soup = bs4.BeautifulSoup(response.text)
    return [a.attrs.get('listname') for a in soup.select('dl#filter_quyu a')]
def get_subarea_info(area_url):
    response = requests.get(area_url)
    soup = bs4.BeautifulSoup(response.text)
    subarea_info = {}
    for a in soup.select('div.subarea a'):
        subarea_info[a.attrs.get('listname')] = a.get_text()
    return subarea_info

def get_subarea_list(subarea_url):
    response = requests.get(subarea_url)
    soup = bs4.BeautifulSoup(response.text)
    return [a.attrs.get('href') for a in soup.select('table.tbimg li.tli1 a')]

def get_detail_page_urls(chuzu_url):
    response = requests.get(chuzu_url)
    soup = bs4.BeautifulSoup(response.text)
    page_urls = []
    try:
        page_urls = [tr.select('td.t a')[0].attrs.get('href') for tr in soup.select('table.tbimg tr') if tr.b.string!=u'面议' and int(tr.b.string) <= 1000]
    except:
        print 'get detail page urls error: url: ', chuzu_url, sys.exc_info()
        exit()
    return page_urls

def get_house_data(detail_page_url):
    house_data = {}
    response = requests.get(detail_page_url)
    soup = bs4.BeautifulSoup(response.text)
    try:
        house_data['title'] = soup.select('h1')[0].get_text()
        house_data['url'] = detail_page_url
        house_data['price'] = soup.select('.bigpri')[0].get_text()
        if len(soup.select('.su_con.w382')) > 0:
            house_data['address'] = soup.select('.su_con.w382')[-1].find(text=True).strip()
        else:
            house_data['address'] = ''
    except:
        exc_type, exc_obj, tb = sys.exc_info()
        print 'get house data error: url: ', detail_page_url, tb.tb_lineno, exc_obj
        house_data = {}
    return house_data

def load_house_info():
    area_lists = get_area_info()
    for area_id in area_lists:
        try:
            [u'hz', u'80', u'81'].index(area_id)
            continue
        except:
            pass
        area_url = list_url + area_id + '/'
        subarea_dicts = get_subarea_info(area_url)
        for subarea_id in subarea_dicts:
            print subarea_dicts[subarea_id].encode('utf-8')
            if subarea_id == area_id:
                continue
            subarea_url = list_url + subarea_id + '/' + '?sort=price_desc'
            xiaoqu_urls = get_subarea_list(subarea_url)
            for xiaoqu_url in xiaoqu_urls:
                url_parts = list(urlparse.urlparse(xiaoqu_url))
                url_parts[2] += 'chuzu/'
                chuzu_url = urlparse.urlunparse(url_parts)
                print chuzu_url
                detail_urls = get_detail_page_urls(chuzu_url)
                for detail_url in detail_urls:
                    mat = re.search('/(\d+)x', urlparse.urlparse(detail_url).path)
                    if mat is None:
                        raise Exception("get error detail url!")
                    house_data = get_house_data(detail_url)
                    if len(house_data) <= 0:
                        continue
                    house_data['sub_name'] = subarea_dicts[subarea_id]
                    house_data['key'] = mat.groups()[0]
                    save_to_db(house_data)
                    time.sleep(1)

def save_to_db(data):
    # Prepare SQL query to INSERT a record into the database.
    sql = "SELECT id FROM find_house WHERE puid = '%s'" % (data['key'])
    try:
        # Execute the SQL command
        cursor.execute(sql)
        results = cursor.fetchone()
        if not results:
            sql = "INSERT INTO find_house(puid, title, url, price, sub_name, address, create_time, update_time) \
                VALUES ('%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d' )" % \
                (data['key'], data['title'], data['url'], data['price'], data['sub_name'], data['address'], time.time(), time.time())
            cursor.execute(sql)
            # Commit your changes in the database
            db.commit()
    except:
        # Rollback in case there is any error
        exc_type, exc_obj, tb = sys.exc_info()
        print "Unexpected error:", data, tb.tb_lineno, exc_obj
        db.rollback()
        exit()

load_house_info()
# disconnect from server
db.close()