drop user if exists 'tvmaze'@'localhost';
create user if not exists 'tvmaze'@'localhost' identified by 'tvmaze';
create database if not exists tvmaze character set utf8 collate utf8_unicode_ci;
grant all on tvmaze.* to 'tvmaze'@'localhost';
flush privileges;

use tvmaze;

drop table if exists shows;
create table shows(
	id int(11) not null primary key auto_increment,
	tvmaze_id int(11) not null default 0 comment 'tvmaze.com/shows/:id',
	imdb_id varchar(30) not null default '' comment 'imdb_id',
	description text not null default '' comment 'summary from json',
	first_air_year date not null default current_timestamp comment 'premiered from json',
	trailer varchar(150) not null default '' comment 'image.original from json',
	showUpdateDate int(11) not null default 0 comment 'modify date from json',
	insert_date timestamp default current_timestamp,
	-- modify_date timestamp default 0,
	unique key tvmaze_id(tvmaze_id)
) comment "shows main table";

drop table if exists episodes;
create table episodes(
	id bigint(20) not null primary key auto_increment,
	episode_id int(11) not null default 0 comment 'id from json',
	show_id int(11) not null default 0 comment 'comment id in TABLE:shows',
	episode_name varchar(30) not null default '' comment 'name from json',
	season_number tinyint(5) unsigned not null default 0 comment 'season from json',
	episode_number tinyint(5) unsigned not null default 0 comment 'number from json',
	image varchar(255) not null default '' comment 'image.original from json',
	summary text not null default '' comment 'summary from json',
	-- modify_date timestamp default 0,
	insert_date timestamp default current_timestamp,
	unique key showEpisodeID(show_id, episode_id)
) comment "episodes has id from shows";

drop table if exists genres;
create table if not exists genres(
	id bigint(20) not null primary key auto_increment,
	genre varchar(30) not null default '',
	insert_date timestamp default current_timestamp,
	key genre(genre)
) comment "only string without relation ids";


drop table if exists shows_genres;
create table if not exists shows_genres(
	id bigint(20) not null primary key auto_increment,
	show_id int(11) not null default 0 comment 'comment id in TABLE:shows',
	genres_id int(11) not null default 0 comment 'comment id in TABLE:genres',
	unique key showGenreID(show_id, genres_id)
) comment "shows_genres  relation between genres and shows";


drop table if exists casts;
create table if not exists casts(
	id bigint(20) not null primary key auto_increment,
	cast_id int(11) not null default 0 comment 'id from json',
	name varchar(30) not null default '' comment 'name in json',
	hero varchar(150) not null default '' comment 'we already have a name so hero will be url from json',
	image varchar(30) not null default 'image.original from json',
	insert_date timestamp default current_timestamp,
	key cast_id(cast_id)
) comment "only info without relation ids";


drop table if exists shows_casts;
create table if not exists shows_casts(
	id bigint(20) not null primary key auto_increment,
	show_id int(11) not null default 0 comment ' id in TABLE:shows',
	cast_id int(11) not null default 0 comment ' id in TABLE:casts',
	unique key showCastID(show_id, cast_id)
) comment "shows_genres  relation between genres and shows";

